<?php

namespace UserBackupLib;

use PDO;
use PDOException;
use UserBackupLib\Exceptions\DatabaseConnectionException;

/**
 * Класс DatabaseConnection управляет подключениями к базам данных.
 */
class DatabaseConnection
{
    /**
     * @var array Список подключений к базам данных (PDO).
     */
    protected $connections = [];

    /**
     * Конструктор.
     *
     * @param array $dbConfigs Массив конфигураций для подключения к базам данных.
     *
     * @throws DatabaseConnectionException
     */
    public function __construct(array $dbConfigs)
    {
        foreach ($dbConfigs as $config) {
            $this->addConnection($config);
        }
    }

    /**
     * Добавляет новое подключение к базе данных.
     *
     * @param array $config Конфигурация для подключения к базе данных.
     * @throws DatabaseConnectionException Если подключение к базе данных не удалось.
     */
    protected function addConnection(array $config)
    {
        try {
            $dsn = "mysql:host={$config['host']};dbname={$config['dbname']}";
            $this->connections[] = new PDO($dsn, $config['user'], $config['password']);
        } catch (PDOException $e) {
            throw new DatabaseConnectionException("Failed to connect to database: {$e->getMessage()}");
        }
    }

    /**
     * Возвращает список подключений к базам данных.
     *
     * @return array Массив подключений (PDO).
     */
    public function getConnections()
    {
        return $this->connections;
    }
}
