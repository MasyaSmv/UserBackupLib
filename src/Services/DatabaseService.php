<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DatabaseServiceInterface;
use App\Services\Concerns\TableFiltering;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * Потоковое получение пользовательских данных из множества подключений.
 */
class DatabaseService implements DatabaseServiceInterface
{
    use TableFiltering;

    protected array $connections;

    /**
     * @param array<int, string> $connections Список имён подключений, зарегистрированных в config/database.php.
     */
    public function __construct(array $connections)
    {
        $this->connections = $connections;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchUserDataFromAllDatabases(string $table, array $params): array
    {
        $allData = [];

        foreach ($this->connections as $connectionName) {
            $data = $this->fetchUserData($table, $params, $connectionName);
            $allData[] = $data;
        }

        return !empty($allData) ? array_merge(...$allData) : [];
    }

    /**
     * {@inheritdoc}
     */
    public function fetchUserData(string $table, array $params, string $connectionName): array
    {
        return iterator_to_array($this->streamUserData($table, $params, $connectionName));
    }

    /**
     * {@inheritdoc}
     */
    public function streamUserData(
        string $table,
        array $params,
        string $connectionName,
        int $chunkSize = 1000
    ): Generator {
        $schema = DB::connection($connectionName)->getSchemaBuilder();

        if (!$schema->hasTable($table)) {
            return;
        }

        $columns = $this->getTableColumns($table, $connectionName);
        $field = $this->determineFilterField($table, $columns);

        if (!$field) {
            return;
        }

        $filterValues = $params[$field] ?? $params['account_id'] ?? [];

        if (empty($filterValues)) {
            return;
        }

        $filterValues = $this->prepareParams($field, $columns, $filterValues);
        $connection = DB::connection($connectionName);

        $page = 1;

        do {
            $chunk = $connection->table($table)
                ->whereIn($field, $filterValues)
                ->orderBy($field)
                ->forPage($page, $chunkSize)
                ->get();

            $page++;

            if ($chunk->isEmpty()) {
                break;
            }

            foreach ($chunk as $row) {
                yield (array) $row;
            }
        } while ($chunk->count() === $chunkSize);
    }

    public function getConnections(): array
    {
        return $this->connections;
    }
}
