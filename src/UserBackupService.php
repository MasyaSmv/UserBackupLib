<?php

namespace UserBackupLib;

use PDO;
use RuntimeException;
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
     * Конструктор.
     *
     * @param array $databases Массив подключений к базам данных (PDO).
     * @param int $userId Идентификатор пользователя.
     */
    public function __construct(array $databases, $userId)
    {
        $this->databases = $databases;
        $this->userId = $userId;
    }

    /**
     * Создает резервную копию данных пользователя.
     *
     * @throws UserDataNotFoundException Если данные пользователя не найдены.
     */
    public function backupUserData()
    {
        $userData = [];

        foreach ($this->databases as $db) {
            $this->appendUserData($userData, $this->fetchUserDataFromDatabase($db));
        }

        if (empty($userData)) {
            throw new UserDataNotFoundException("No user data found for user ID: {$this->userId}");
        }

        $this->saveToFile($userData);
    }

    /**
     * Извлекает данные пользователя из базы данных.
     *
     * @param \PDO $db Объект подключения к базе данных.
     * @return array Массив с данными пользователя.
     */
    protected function fetchUserDataFromDatabase($db)
    {
        $userTables = $this->getUserRelatedTables($db);
        $userData = [];

        foreach ($userTables as $table) {
            $query = $this->buildUserQuery($table);
            $statement = $db->prepare($query);
            $statement->execute(['user_id' => $this->userId]);
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
            if ($this->hasUserIdColumn($db, $table)) {
                $userRelatedTables[] = $table;
            }
        }

        return $userRelatedTables;
    }

    /**
     * Проверяет, содержит ли таблица столбец с идентификатором пользователя.
     *
     * @param PDO $db Объект подключения к базе данных.
     * @param string $table Название таблицы.
     * @return bool True, если столбец существует, иначе False.
     */
    protected function hasUserIdColumn($db, $table)
    {
        $query = $db->query("SHOW COLUMNS FROM $table LIKE 'user_id'");
        return (bool)$query->fetch();
    }

    /**
     * Строит SQL-запрос для извлечения данных пользователя из таблицы.
     *
     * @param string $table Название таблицы.
     * @return string SQL-запрос.
     */
    protected function buildUserQuery($table)
    {
        return "SELECT * FROM $table WHERE user_id = :user_id";
    }

    /**
     * Сохраняет данные пользователя в файл.
     *
     * @param array $data Массив данных пользователя.
     */
    protected function saveToFile($data)
    {
        $backupDir = __DIR__ . '/../backups/';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $backupDir));
        }

        $filePath = $backupDir . 'user_' . $this->userId . '_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));

        $this->encryptFile($filePath);
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

        $command = "openssl enc -aes-256-cbc -salt -in $filePath -out $outputPath -k $password";
        shell_exec($command);

        unlink($filePath);
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
            if (isset($userData[$table])) {
                $userData[$table] = array_merge($userData[$table], $data);
            } else {
                $userData[$table] = $data;
            }
        }
    }
}
