<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use Illuminate\Support\Facades\DB;

trait TableFiltering
{
    protected function getTableColumns(string $table, string $connectionName): array
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $columns = $connection->select(
                'PRAGMA table_info(' . $connection->getPdo()->quote($table) . ')'
            );

            return array_map(static function ($column) {
                return $column->name;
            }, $columns);
        }

        // MySQL, MariaDB
        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table],
        );

        return array_map(static function ($column) {
            return $column->COLUMN_NAME;
        }, $result);
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

        if (in_array('user_id', $columns, true)) {
            return 'user_id';
        }

        if (in_array('account_id', $columns, true)) {
            return 'account_id';
        }

        if (in_array('from_account_id', $columns, true)) {
            return 'from_account_id';
        }

        if (in_array('to_account_id', $columns, true)) {
            return 'to_account_id';
        }

        if (in_array('active_id', $columns, true)) {
            return 'active_id';
        }

        return null;
    }

    protected function prepareParams(string $field, array $columns, array $params): array
    {
        return $params;
    }

    /**
     * Возвращает имя первичного ключа, пригодного для keyset-пагинации
     * (`WHERE pk > :last ORDER BY pk LIMIT n`): ровно одна числовая колонка.
     *
     * Для составных/строковых/UUID первичных ключей возвращает null — вызывающий код
     * должен откатиться на OFFSET-пагинацию. Это защищает generic-пакет от таблиц,
     * где keyset по единственному числовому PK невозможен.
     */
    protected function determinePrimaryKey(string $table, string $connectionName): ?string
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $columns = $connection->select(
                'PRAGMA table_info(' . $connection->getPdo()->quote($table) . ')'
            );

            $primary = array_values(array_filter($columns, static function ($column) {
                return (int) $column->pk > 0;
            }));

            if (count($primary) !== 1) {
                return null;
            }

            return $this->isNumericColumnType((string) $primary[0]->type)
                ? (string) $primary[0]->name
                : null;
        }

        // MySQL, MariaDB
        $database = $connection->getDatabaseName();
        $primary = $connection->select(
            'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_KEY = ?',
            [$database, $table, 'PRI'],
        );

        if (count($primary) !== 1) {
            return null;
        }

        return $this->isNumericColumnType((string) $primary[0]->DATA_TYPE)
            ? (string) $primary[0]->COLUMN_NAME
            : null;
    }

    private function isNumericColumnType(string $type): bool
    {
        // Числовые типы, пригодные для keyset-курсора (монотонный автоинкремент).
        $numericTypes = ['int', 'integer', 'bigint', 'mediumint', 'smallint', 'tinyint'];

        // Отсекаем размерности/атрибуты: "bigint(20) unsigned" -> "bigint".
        $normalized = strtolower(trim($type));
        $normalized = preg_replace('/[\s(].*$/', '', $normalized) ?? $normalized;

        return in_array($normalized, $numericTypes, true);
    }
}
