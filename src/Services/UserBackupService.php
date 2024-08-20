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
