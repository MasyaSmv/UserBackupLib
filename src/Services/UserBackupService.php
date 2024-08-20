<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use UserBackupLib\Exceptions\BackupException;
use UserBackupLib\Exceptions\UserDataNotFoundException;

/**
 * Класс UserBackupService отвечает за резервное копирование данных пользователя в Laravel.
 */
class UserBackupService
{
    protected $userId;
    protected $accountIds;
    protected $activeIds;
    protected $ignoredTables;
    protected $userData = [];

    /**
     * Конструктор.
     *
     * @param int $userId Идентификатор пользователя.
     * @param array $accountIds Массив идентификаторов счетов пользователя.
     * @param array $activeIds Массив идентификаторов активов пользователя.
     * @param array $ignoredTables Массив таблиц, которые нужно игнорировать.
     */
    public function __construct(int $userId, array $accountIds = [], array $activeIds = [], array $ignoredTables = [])
    {
        $this->userId = $userId;
        $this->accountIds = $accountIds;
        $this->activeIds = $activeIds;
        $this->ignoredTables = $ignoredTables;
    }

    /**
     * Устанавливает список таблиц для игнорирования.
     *
     * @param array $ignoredTables Массив имен таблиц для игнорирования.
     */
    public function setIgnoredTables(array $ignoredTables)
    {
        $this->ignoredTables = $ignoredTables;
    }

    /**
     * Получает все данные пользователя из баз данных.
     *
     * @throws UserDataNotFoundException Если данные пользователя не найдены.
     * @return array Массив с данными пользователя.
     */
    public function fetchAllUserData()
    {
        $this->userData = [];

        $tables = $this->getUserRelatedTables();

        foreach ($tables as $table) {
            if (in_array($table, $this->ignoredTables)) {
                continue;
            }

            $query = $this->buildUserQuery($table);
            $data = DB::select($query, $this->prepareQueryParams());

            if (!empty($data)) {
                $this->userData[$table] = $data;
            }
        }

        if (empty($this->userData)) {
            throw new UserDataNotFoundException("No user data found for user ID: {$this->userId}");
        }

        return $this->userData;
    }

    /**
     * Сохраняет данные пользователя в файл.
     *
     * @param string $filePath Путь к файлу для сохранения.
     * @param bool $encrypt Флаг для шифрования файла (по умолчанию true).
     *
     * @throws BackupException
     */
    public function saveToFile(string $filePath, bool $encrypt = true)
    {
        if (empty($this->userData)) {
            throw new BackupException("No data available to save. Please fetch data first.");
        }

        file_put_contents($filePath, json_encode($this->userData, JSON_PRETTY_PRINT));

        if ($encrypt) {
            $this->encryptFile($filePath);
        }
    }

    /**
     * Возвращает список таблиц, связанных с пользователем.
     *
     * @return array Массив с названиями таблиц.
     */
    protected function getUserRelatedTables()
    {
        return DB::connection()->getDoctrineSchemaManager()->listTableNames();
    }

    /**
     * Строит SQL-запрос для извлечения данных пользователя из таблицы.
     *
     * @param string $table Название таблицы.
     * @return string SQL-запрос.
     */
    protected function buildUserQuery(string $table)
    {
        $columns = DB::connection()->getDoctrineSchemaManager()->listTableColumns($table);

        $field = 'user_id';

        if (isset($columns['account_id'])) {
            $field = 'account_id';
        } elseif (isset($columns['from_account_id'])) {
            $field = 'from_account_id';
        } elseif (isset($columns['to_account_id'])) {
            $field = 'to_account_id';
        } elseif (isset($columns['active_id'])) {
            $field = 'active_id';
        }

        return "SELECT * FROM {$table} WHERE {$field} IN (:user_ids)";
    }

    /**
     * Подготавливает параметры для SQL-запроса.
     *
     * @return array Параметры для SQL-запроса.
     */
    protected function prepareQueryParams()
    {
        $userIds = array_merge([$this->userId], $this->accountIds, $this->activeIds);
        return ['user_ids' => $userIds];
    }

    /**
     * Шифрует файл с данными пользователя.
     *
     * @param string $filePath Путь к файлу.
     */
    protected function encryptFile(string $filePath)
    {
        $outputPath = $filePath . '.enc';
        $password = env('BACKUP_ENCRYPTION_KEY', 'your-secret-password');

        $command = "openssl enc -aes-256-cbc -salt -in {$filePath} -out {$outputPath} -k {$password}";
        shell_exec($command);

        unlink($filePath);
    }
}
