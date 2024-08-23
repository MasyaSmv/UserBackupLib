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

    /**
     * Свойство для хранения данных
     *
     * @var array
     */
    protected array $userData = [];

    /**
     * @param int $userId
     * @param DatabaseService $databaseService
     * @param BackupProcessor $backupProcessor
     * @param FileStorageService $fileStorageService
     * @param array $accountIds
     * @param array $activeIds
     * @param array $ignoredTables
     */
    public function __construct(
        int $userId,
        DatabaseService $databaseService,
        BackupProcessor $backupProcessor,
        FileStorageService $fileStorageService,
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
     * Статический метод для создания экземпляра UserBackupService с инициализированными сервисами.
     *
     * @param int $userId
     * @param array $accountIds Список идентификаторов субсчетов
     * @param array $activeIds Список идентификаторов активов
     * @param array $ignoredTables Список игнорируемых таблиц
     * @param array $connections Список имен подключений к базам данных
     *
     * @return UserBackupService
     */
    public static function create(
        int $userId,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = [],
        array $connections = []
    ): self {
        // Инициализация сервиса для работы с несколькими базами данных
        $databaseService = new DatabaseService($connections);

        // Инициализация остальных сервисов
        $backupProcessor = new BackupProcessor();
        $fileStorageService = new FileStorageService();

        return new self(
            $userId,
            $databaseService,
            $backupProcessor,
            $fileStorageService,
            $accountIds,
            $activeIds,
            $ignoredTables
        );
    }

    /**
     * Получает все данные пользователя из всех баз данных.
     *
     * @return array
     */
    public function fetchAllUserData(): array
    {
        foreach ($this->databaseService->getConnections() as $connectionName) {
            // Получаем список таблиц для текущего подключения
            $tables = DB::connection($connectionName)->getDoctrineSchemaManager()->listTableNames();

            foreach ($tables as $table) {
                // Пропускаем таблицы, которые нужно игнорировать
                if (in_array($table, $this->ignoredTables)) {
                    continue;
                }

                // Проверяем наличие таблицы в базе данных
                if (!DB::connection($connectionName)->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                $params = array_merge([$this->userId], $this->accountIds, $this->activeIds);
                // Извлекаем данные для текущей таблицы из текущего подключения
                $data = $this->databaseService->fetchUserData($table, $params, $connectionName);

                if (!empty($data)) {
                    $this->backupProcessor->appendUserData($table, $data);
                }
            }
        }

        // Сохраняем собранные данные в свойство $userData
        $this->userData = $this->backupProcessor->getUserData();

        return $this->userData;
    }

    /**
     * Формирует путь, вызывает сохранение файла и возвращает до него путь
     *
     * @param bool $encrypt Шифровать ли файл
     *
     * @return string
     */
    public function saveBackupToFile(bool $encrypt = true): string
    {
        $userId = $this->userId;
        $date = date('Y-m-d');
        $time = date('H-i-s');

        $filePath = base_path("resources/backup_actives/$userId/$date/$time.json");

        // Используем сохраненные данные из $userData
        return $this->fileStorageService->saveToFile($filePath, $this->userData, $encrypt);
    }
}
