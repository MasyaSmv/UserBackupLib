<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DatabaseServiceInterface;
use App\Contracts\UserDataDeletionServiceInterface;
use App\Services\Concerns\TableFiltering;
use Illuminate\Support\Facades\DB;

/**
 * Удаляет данные пользователя по всем таблицам во всех подключениях.
 */
class UserDataDeletionService implements UserDataDeletionServiceInterface
{
    use TableFiltering;

    protected DatabaseServiceInterface $databaseService;

    /**
     * @param DatabaseServiceInterface $databaseService
     */
    public function __construct(DatabaseServiceInterface $databaseService)
    {
        $this->databaseService = $databaseService;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteUserData(
        int $userId,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = []
    ): void {
        foreach ($this->databaseService->getConnections() as $connectionName) {
            $tables = $this->getTables($connectionName);

            foreach ($tables as $table) {
                if (in_array($table, $ignoredTables, true)) {
                    continue;
                }

                if (!DB::connection($connectionName)->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                $columns = $this->getTableColumns($table, $connectionName);
                $field = $this->determineFilterField($table, $columns);

                if (!$field) {
                    continue;
                }

                $params = $this->buildParams($table, $field, $userId, $accountIds, $activeIds);

                if (empty($params)) {
                    continue;
                }

                $prepared = $this->prepareParams($field, $columns, $params);
                $this->deleteRows($connectionName, $table, $field, $prepared);
            }
        }
    }

    private function buildParams(
        string $table,
        string $field,
        int $userId,
        array $accountIds,
        array $activeIds
    ): array {
        // users.id = userId
        if ($table === 'users' && $field === 'id') {
            return [$userId];
        }

        // user_subaccounts.id = subaccountIds
        if ($table === 'user_subaccounts' && $field === 'id') {
            return $accountIds;
        }

        // Универсальные поля
        if ($field === 'user_id') {
            return [$userId];
        }

        if (in_array($field, ['account_id', 'from_account_id', 'to_account_id', 'subaccount_id'], true)) {
            return $accountIds;
        }

        if ($field === 'active_id') {
            return $activeIds;
        }

        /**
         * Ключевой момент:
         * если фильтр = id, но мы не знаем, какие id подставлять — лучше НЕ удалять,
         * чем удалить не то (или, как сейчас, удалить одну “случайную” запись).
         */
        return [];
    }


    private function deleteRows(string $connectionName, string $table, string $field, array $ids): void
    {
        $connection = DB::connection($connectionName);

        foreach (array_chunk($ids, 500) as $chunk) {
            $connection->table($table)->whereIn($field, $chunk)->delete();
        }
    }

    /**
     * Возвращает список таблиц для подключения без зависимости от Doctrine DBAL.
     *
     * @param string $connectionName
     * @return array<int, string>
     */
    private function getTables(string $connectionName): array
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");

            return array_map(static function ($row) {
                return $row->name;
            }, $rows);
        }

        $rows = $connection->select('SHOW TABLES');

        return array_map('current', $rows);
    }
}
