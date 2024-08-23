<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DatabaseService
{
    protected array $connections;

    /**
     * Конструктор для инициализации подключений к базам данных.
     *
     * @param array $connections Список конфигураций для подключений.
     */
    public function __construct(array $connections)
    {
        $this->connections = $connections;
    }

    /**
     * Извлекает данные пользователя из всех указанных баз данных.
     *
     * @param string $table
     * @param array $params
     * @return array
     */
    public function fetchUserDataFromAllDatabases(string $table, array $params): array
    {
        $allData = [];

        foreach ($this->connections as $connectionName) {
            $data = $this->fetchUserData($table, $params, $connectionName);
            $allData[] = $data;
        }

        // Объединяем данные из всех подключений в одном массиве
        return !empty($allData) ? array_merge(...$allData) : [];
    }

    /**
     * Извлекает данные пользователя из указанной таблицы и базы данных.
     *
     * @param string $table
     * @param array $params
     * @param string $connectionName
     *
     * @return array
     */
    public function fetchUserData(string $table, array $params, string $connectionName): array
    {
        // Проверяем наличие таблицы в базе данных перед выполнением запроса
        if (!DB::connection($connectionName)->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        $columns = $this->getTableColumns($table, $connectionName);
        $field = $this->determineFilterField($table, $columns);

        // Если поле для фильтрации не найдено, пропускаем запрос
        if (!$field) {
            return [];
        }

        $params = $this->prepareParams($field, $columns, $params);
        $query = $this->buildQuery($table, $field, $params);

        return DB::connection($connectionName)->select($query, $params);
    }


    /**
     * Получает список колонок для указанной таблицы и базы данных.
     *
     * @param string $table
     * @param string $connectionName
     *
     * @return array
     */
    protected function getTableColumns(string $table, string $connectionName): array
    {
        return DB::connection($connectionName)->getDoctrineSchemaManager()->listTableColumns($table);
    }

    /**
     * Определяет поле для фильтрации данных в таблице.
     *
     * @param string $table
     * @param array $columns
     *
     * @return string|null
     */
    protected function determineFilterField(string $table, array $columns): ?string
    {
        if ($table === 'user_subaccounts') {
            return 'id';
        }

        if (isset($columns['user_id'])) {
            return 'user_id';
        }

        if (isset($columns['account_id'])) {
            return 'account_id';
        }

        if (isset($columns['from_account_id'])) {
            return 'from_account_id';
        }

        if (isset($columns['to_account_id'])) {
            return 'to_account_id';
        }

        if (isset($columns['active_id'])) {
            return 'active_id';
        }

        return null;
    }

    /**
     * Подготавливает параметры для фильтрации, учитывая текстовый формат user_id.
     *
     * @param string $field
     * @param array $columns
     * @param array $params
     *
     * @return array
     */
    protected function prepareParams(string $field, array $columns, array $params): array
    {
        if ($field === 'user_id' && isset($columns['user_id']) && $this->isUserIdText($columns['user_id'])) {
            return array_map(static function ($id) {
                return config('app.env') . '-' . $id;
            }, $params);
        }

        return $params;
    }

    /**
     * Строит SQL-запрос для указанной таблицы и поля.
     *
     * @param string $table
     * @param string $field
     * @param array $params
     *
     * @return string
     */
    protected function buildQuery(string $table, string $field, array $params): string
    {
        $placeholders = implode(',', array_fill(0, count($params), '?'));

        return "SELECT * FROM $table WHERE $field IN ($placeholders)";
    }

    /**
     * Проверяет, является ли поле 'user_id' текстовым.
     *
     * @param object $column
     *
     * @return bool
     */
    protected function isUserIdText(object $column): bool
    {
        return in_array(strtolower($column->getType()->getName()), ['string', 'text']);
    }

    /**
     * Возвращает все имена подключений
     *
     * @return array
     */
    public function getConnections(): array
    {
        return $this->connections;
    }
}
