<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use Illuminate\Support\Facades\DB;

trait TableFiltering
{
    protected function getTableColumns(string $table, string $connectionName): array
    {
        return DB::connection($connectionName)
            ->getDoctrineSchemaManager()
            ->listTableColumns($table);
    }

    /**
     * Определяет поле фильтрации для таблицы исходя из набора колонок.
     *
     * @param string $table
     * @param array  $columns
     *
     * @return string|null
     */
    protected function determineFilterField(string $table, array $columns): ?string
    {
        if ($table === 'user_subaccounts' || $table === 'users') {
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

    protected function prepareParams(string $field, array $columns, array $params): array
    {
        if ($field === 'user_id'
            && isset($columns['user_id'])
            && $this->isUserIdText($columns['user_id'])
        ) {
            return array_map(static function ($id) {
                return config('app.env') . '-' . $id;
            }, $params);
        }

        return $params;
    }

    /**
     * Проверяет текстовый ли тип user_id, чтобы префиксовать env.
     *
     * @param object $column
     *
     * @return bool
     */
    protected function isUserIdText(object $column): bool
    {
        return in_array(strtolower($column->getType()->getName()), ['string', 'text'], true);
    }
}
