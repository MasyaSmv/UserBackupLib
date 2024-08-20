<?php

namespace UserBackupLib\Services;

use PDO;
use UserBackupLib\Exceptions\BackupException;
use UserBackupLib\Exceptions\UserDataNotFoundException;

/**
 * Класс UserBackupService отвечает за резервное копирование данных пользователя.
 */
class UserBackupService
{
    /**
     * @var array Список подключений к базам данных.
     */
    protected $databases;

    /**
     * @var int Идентификатор пользователя.
     */
    protected $userId;

    /**
     * @var array Идентификаторы счетов пользователя.
     */
    protected $accountIds;

    /**
     * @var array Массив с данными пользователя.
     */
    protected $userData = [];

    /**
     * @var array Список таблиц для игнорирования.
     */
    protected $ignoredTables = [];

    /**
     * Конструктор.
     *
     * @param array $databases Массив подключений к базам данных (PDO).
     * @param int $userId Идентификатор пользователя.
     * @param array $accountIds Массив идентификаторов счетов пользователя.
     * @param array $ignoredTables Массив таблиц, которые нужно игнорировать.
     */
    public function __construct(array $databases, $userId, array $accountIds = [], array $ignoredTables = [])
    {
        $this->databases = $databases;
        $this->userId = $userId;
        $this->accountIds = $accountIds;
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

        foreach ($this->databases as $db) {
            $this->appendUserData($this->userData, $this->fetchUserDataFromDatabase($db));
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
     */
    public function saveToFile($filePath, $encrypt = true)
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
     * Извлекает данные пользователя из базы данных.
     *
     * @param PDO $db Объект подключения к базе данных.
     * @return array Массив с данными пользователя.
     */
    protected function fetchUserDataFromDatabase($db)
    {
        $userTables = $this->getUserRelatedTables($db);
        $userData = [];

        foreach ($userTables as $table) {
            // Проверяем, не находится ли таблица в списке игнорируемых
            if (in_array($table, $this->ignoredTables)) {
                continue;
            }

            $query = $this->buildUserQuery($db, $table);
            $statement = $db->prepare($query);

            // Подготовка параметров для запроса
            $params = $this->prepareQueryParams();

            $statement->execute($params);
            $data = $statement->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($data)) {
                $userData[$table] = $data;
            }
        }

        return $userData;
    }

    /**
     * Возвращает список таблиц, связанных с пользователем, из базы данных.
     *
     * @param PDO $db Объект подключения к базе данных.
     * @return array Массив с названиями таблиц.
     */
    protected function getUserRelatedTables($db)
    {
        $query = $db->query("SHOW TABLES");
        $tables = $query->fetchAll(PDO::FETCH_COLUMN);
        $userRelatedTables = [];

        foreach ($tables as $table) {
            if ($this->hasUserIdentifierColumn($db, $table)) {
                $userRelatedTables[] = $table;
            }
        }

        return $userRelatedTables;
    }

    /**
     * Проверяет, содержит ли таблица столбец с идентификатором пользователя или счета.
     *
     * @param PDO $db Объект подключения к базе данных.
     * @param string $table Название таблицы.
     * @return bool True, если столбец существует, иначе False.
     */
    protected function hasUserIdentifierColumn($db, $table)
    {
        $query = $db->prepare("
            SHOW COLUMNS FROM {$table} 
            LIKE 'user_id' OR LIKE 'account_id' OR LIKE 'from_account_id' OR LIKE 'to_account_id'
        ");
        $query->execute();
        return $query->fetch() ? true : false;
    }

    /**
     * Строит SQL-запрос для извлечения данных пользователя из таблицы.
     *
     * @param PDO $db Объект подключения к базе данных.
     * @param string $table Название таблицы.
     * @return string SQL-запрос.
     */
    protected function buildUserQuery($db, $table)
    {
        $query = $db->prepare("
            SHOW COLUMNS FROM {$table} 
            LIKE 'user_id' OR LIKE 'account_id' OR LIKE 'from_account_id' OR LIKE 'to_account_id'
        ");
        $query->execute();
        $column = $query->fetch(PDO::FETCH_ASSOC);

        $columnName = $column ? $column['Field'] : 'user_id'; // Если нет поля, выбираем user_id по умолчанию.

        return "SELECT * FROM {$table} WHERE {$columnName} = :user_id";
    }

    /**
     * Подготавливает параметры для SQL-запроса.
     *
     * @return array Параметры для SQL-запроса.
     */
    protected function prepareQueryParams()
    {
        if (!empty($this->accountIds)) {
            return ['user_id' => $this->accountIds];
        }
        return ['user_id' => $this->userId];
    }

    /**
     * Добавляет новые данные пользователя к уже существующим данным.
     *
     * @param array $userData Ссылка на массив с уже существующими данными пользователя.
     * @param array $newData Массив с новыми данными пользователя.
     */
    protected function appendUserData(&$userData, $newData)
    {
        foreach ($newData as $table => $data) {
            if (!isset($userData[$table])) {
                $userData[$table] = [];
            }

            foreach ($data as $row) {
                $userData[$table][] = $row;
            }
        }
    }

    /**
     * Шифрует файл с данными пользователя.
     *
     * @param string $filePath Путь к файлу.
     */
    protected function encryptFile($filePath)
    {
        $outputPath = $filePath . '.enc';
        $password = 'your-secret-password';

        $command = "openssl enc -aes-256-cbc -salt -in {$filePath} -out {$outputPath} -k {$password}";
        shell_exec($command);

        unlink($filePath);
    }
}
