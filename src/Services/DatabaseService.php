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
        $columns = DB::connection()->getDoctrineSchemaManager()->listTableColumns($table);

        $field = $this->determineFilterField($columns);

        if (!$field) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($params), '?'));

        $query = "SELECT * FROM $table WHERE $field IN ($placeholders)";
        return DB::select($query, $params);
    }

    /**
     * Определяет поле для фильтрации данных в таблице.
     *
     * @param array $columns
     * @return string|null
     */
    protected function determineFilterField(array $columns): ?string
    {
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
}
