<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\BackupProcessorInterface;
use App\Contracts\DatabaseServiceInterface;
use App\Contracts\FileStorageServiceInterface;
use App\Contracts\UserBackupServiceInterface;
use App\Services\UserBackupServiceFactory;
use App\ValueObjects\UserDataScope;
use Illuminate\Support\Facades\DB;

/**
 * Фасад, координирующий потоковую выгрузку, шифрование и сохранение бэкапа пользователя.
 */
class UserBackupService implements UserBackupServiceInterface
{
    protected UserDataScope $scope;

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
        $this->scope = new UserDataScope($userId, $accountIds, $activeIds, $ignoredTables);
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
        $factory = new UserBackupServiceFactory(
            new DatabaseService($connections),
            new BackupProcessor(),
            new FileStorageService(),
        );

        /** @var self $service */
        $service = $factory->makeForUser($userId, $accountIds, $activeIds, $ignoredTables);

        return $service;
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
                if ($this->scope->isIgnoredTable($table)) {
                    continue;
                }

                if (!DB::connection($connectionName)->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                $params = $this->scope->backupParametersForTable($table);

                $stream = $this->databaseService->streamUserData($table, $params->toArray(), $connectionName);

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
     * Сохраняет собранный бэкап в указанный путь.
     *
     * @param string $filePath Путь, куда нужно сохранить бэкап.
     * @param bool $encrypt Признак шифрования результата.
     *
     * @return string
     */
    public function saveBackupToFile(string $filePath, bool $encrypt = true): string
    {
        return $this->fileStorageService->saveToFile($filePath, $this->userData, $encrypt);
    }
}
