<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DatabaseService
{
    /**
     * Извлекает данные пользователя из указанной таблицы.
     *
     * @param string $table
     * @param array $params
     * @return array
     */
    public function fetchUserData(string $table, array $params): array
    {
        $columns = $this->getTableColumns($table);
        $field = $this->determineFilterField($table, $columns);

        if (!$field) {
            return [];
        }

        $params = $this->prepareParams($field, $columns, $params);
        $query = $this->buildQuery($table, $field, $params);

        return DB::select($query, $params);
    }

    /**
     * Получает список колонок для указанной таблицы.
     *
     * @param string $table
     * @return array
     */
    protected function getTableColumns(string $table): array
    {
        return DB::connection()->getDoctrineSchemaManager()->listTableColumns($table);
    }

    /**
     * Определяет поле для фильтрации данных в таблице.
     *
     * @param string $table
     * @param array $columns
     * @return string|null
     */
    protected function determineFilterField(string $table, array $columns): ?string
    {
        if ($table === 'user_account_currencies') {
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
}
