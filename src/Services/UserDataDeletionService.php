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
            $tables = DB::connection($connectionName)->getDoctrineSchemaManager()->listTableNames();

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

                $params = $this->buildParams($field, $userId, $accountIds, $activeIds);

                if (empty($params)) {
                    continue;
                }

                $prepared = $this->prepareParams($field, $columns, $params);
                $this->deleteRows($connectionName, $table, $field, $prepared);
            }
        }
    }

    private function buildParams(string $field, int $userId, array $accountIds, array $activeIds): array
    {
        if ($field === 'id') {
            return [$userId];
        }

        if ($field === 'user_id') {
            return [$userId];
        }

        if ($field === 'account_id' || $field === 'from_account_id' || $field === 'to_account_id') {
            return $accountIds;
        }

        if ($field === 'active_id') {
            return $activeIds;
        }

        return [];
    }

    private function deleteRows(string $connectionName, string $table, string $field, array $ids): void
    {
        $connection = DB::connection($connectionName);

        foreach (array_chunk($ids, 500) as $chunk) {
            $connection->table($table)->whereIn($field, $chunk)->delete();
        }
    }
}
