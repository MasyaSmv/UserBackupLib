<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\BackupProcessorInterface;
use App\Contracts\DatabaseServiceInterface;
use App\Contracts\FileStorageServiceInterface;
use App\Contracts\UserBackupServiceInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Фасад, координирующий потоковую выгрузку, шифрование и сохранение бэкапа пользователя.
 */
class UserBackupService implements UserBackupServiceInterface
{
    protected int $userId;
    protected array $accountIds;
    protected array $activeIds;
    protected array $ignoredTables;

    protected DatabaseServiceInterface $databaseService;
    protected BackupProcessorInterface $backupProcessor;
    protected FileStorageServiceInterface $fileStorageService;

    /**
     * @var array<string, array<int, iterable>>
     */
    protected array $userData = [];

    /**
     * @param int                             $userId
     * @param DatabaseServiceInterface        $databaseService
     * @param BackupProcessorInterface        $backupProcessor
     * @param FileStorageServiceInterface     $fileStorageService
     * @param array<int, int|string>          $accountIds
     * @param array<int, int|string>          $activeIds
     * @param array<int, string>              $ignoredTables
     */
    public function __construct(
        int $userId,
        DatabaseServiceInterface $databaseService,
        BackupProcessorInterface $backupProcessor,
        FileStorageServiceInterface $fileStorageService,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = []
    ) {
        $this->userId = $userId;
        $this->accountIds = $accountIds;
        $this->activeIds = $activeIds;
        $this->ignoredTables = $ignoredTables;
        $this->databaseService = $databaseService;
        $this->backupProcessor = $backupProcessor;
        $this->fileStorageService = $fileStorageService;
    }

    /**
     * Упрощённый фабричный метод без DI-контейнера.
     *
     * @param int        $userId
     * @param array      $accountIds
     * @param array      $activeIds
     * @param array      $ignoredTables
     * @param array      $connections
     *
     * @return self
     */
    public static function create(
        int $userId,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = [],
        array $connections = []
    ): self {
        $databaseService = new DatabaseService($connections);
        $backupProcessor = new BackupProcessor();
        $fileStorageService = new FileStorageService();

        return new self(
            $userId,
            $databaseService,
            $backupProcessor,
            $fileStorageService,
            $accountIds,
            $activeIds,
            $ignoredTables,
        );
    }

    /**
     * @return array<string, array<int, iterable>>
     */
    public function fetchAllUserData(): array
    {
        $this->backupProcessor->clearUserData();

        foreach ($this->databaseService->getConnections() as $connectionName) {
            $tables = $this->getTables($connectionName);

            foreach ($tables as $table) {
                if (in_array($table, $this->ignoredTables, true)) {
                    continue;
                }

                if (!DB::connection($connectionName)->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                $params = $table === 'users'
                    ? ['id' => [$this->userId]]
                    : [
                        'user_id' => [$this->userId],
                        'account_id' => $this->accountIds,
                        'active_id' => $this->activeIds,
                    ];

                $stream = $this->databaseService->streamUserData($table, $params, $connectionName);

                $this->backupProcessor->appendUserData($table, $stream);
            }
        }

        $this->userData = $this->backupProcessor->getUserData();

        return $this->userData;
    }

    /**
     * Возвращает список таблиц для подключения без зависимостей от Doctrine DBAL.
     *
     * @param string $connectionName
     * @return array<int, string>
     */
    private function getTables(string $connectionName): array
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

            return array_map(static function ($row) {
                return $row->name;
            }, $rows);
        }

        $rows = $connection->select('SHOW TABLES');

        return array_map('current', $rows);
    }

    /**
     * Сохраняет собранный бэкап в файл; путь формируется по userId и текущему времени.
     *
     * @param bool $encrypt Признак шифрования результата.
     *
     * @return string
     */
    public function saveBackupToFile(bool $encrypt = true): string
    {
        $userId = $this->userId;
        $date = date('Y-m-d');
        $time = date('H-i-s');

        $filePath = base_path("resources/backup_actives/$userId/$date/$time.json");

        $directoryPath = dirname($filePath);
        if (!is_dir($directoryPath) && !mkdir($directoryPath, 0755, true) && !is_dir($directoryPath)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $directoryPath));
        }

        return $this->fileStorageService->saveToFile($filePath, $this->userData, $encrypt);
    }
}
