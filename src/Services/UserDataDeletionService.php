<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DatabaseServiceInterface;
use App\Contracts\UserDataDeletionServiceInterface;
use App\Services\Concerns\TableFiltering;
use App\ValueObjects\FilterValues;
use App\ValueObjects\UserDataScope;
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
    public function deleteScope(UserDataScope $scope): void
    {
        foreach ($this->databaseService->getConnections() as $connectionName) {
            $tables = $this->getTables($connectionName);

            foreach ($tables as $table) {
                if ($scope->isIgnoredTable($table)) {
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

                $params = $this->buildParams($scope, $table, $field);

                if ($params->isEmpty()) {
                    continue;
                }

                $prepared = $this->prepareParams($field, $columns, $params->toArray());
                $this->deleteRows($connectionName, $table, $field, $prepared);
            }
        }
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
        $this->deleteScope(new UserDataScope($userId, $accountIds, $activeIds, $ignoredTables));
    }

    private function buildParams(
        UserDataScope $scope,
        string $table,
        string $field,
    ): FilterValues {
        return $scope->deletionValuesFor($table, $field);
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
