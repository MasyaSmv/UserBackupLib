<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class UserBackupService
{
    protected int $userId;
    protected array $accountIds;
    protected array $activeIds;
    protected array $ignoredTables;

    protected DatabaseService $databaseService;
    protected BackupProcessor $backupProcessor;
    protected FileStorageService $fileStorageService;

    public function __construct(
        int $userId,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = [],
        DatabaseService $databaseService,
        BackupProcessor $backupProcessor,
        FileStorageService $fileStorageService
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
     * Статический метод для создания экземпляра UserBackupService с инициализированными сервисами.
     *
     * @param int $userId
     * @param array $accountIds
     * @param array $activeIds
     * @param array $ignoredTables
     * @return UserBackupService
     */
    public static function create(
        int $userId,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = []
    ): self {
        $databaseService = new DatabaseService();
        $backupProcessor = new BackupProcessor();
        $fileStorageService = new FileStorageService();

        return new self(
            $userId,
            $accountIds,
            $activeIds,
            $ignoredTables,
            $databaseService,
            $backupProcessor,
            $fileStorageService
        );
    }

    /**
     * Получает все данные пользователя из базы данных.
     *
     * @return array
     */
    public function fetchAllUserData(): array
    {
        $tables = DB::connection()->getDoctrineSchemaManager()->listTableNames();

        foreach ($tables as $table) {
            if (in_array($table, $this->ignoredTables)) {
                continue;
            }

            $params = array_merge([$this->userId], $this->accountIds, $this->activeIds);
            $data = $this->databaseService->fetchUserData($table, $params);

            $this->backupProcessor->appendUserData($table, $data);
        }

        return $this->backupProcessor->getUserData();
    }

    /**
     * Формирует путь и вызывает сохранение файла
     *
     * @return void
     */
    public function saveBackupToFile()
    {
        $userId = $this->userId;
        $date = date('Y-m-d');
        $time = date('H-i-s');

        $filePath = base_path("resources/backup_actives/$userId/$date/$time.json");

        $this->saveToFile($filePath);
    }

    /**
     * Сохраняет все данные пользователя в файл.
     *
     * @param string $filePath
     */
    public function saveToFile(string $filePath): void
    {
        $data = $this->backupProcessor->getUserData();
        $this->fileStorageService->saveToFile($filePath, $data);
    }
}
