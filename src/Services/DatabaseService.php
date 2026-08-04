<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DatabaseServiceInterface;
use App\Contracts\TableFilter;
use App\Services\Concerns\TableFiltering;
use App\ValueObjects\ConnectionNames;
use App\ValueObjects\LiteralFilter;
use App\ValueObjects\SubqueryFilter;
use App\ValueObjects\TableQueryParameters;
use Generator;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Потоковое получение пользовательских данных из множества подключений.
 */
class DatabaseService implements DatabaseServiceInterface
{
    use TableFiltering;

    /**
     * Размер чанка по умолчанию. Крупнее исторического значения, т.к. keyset-пагинация
     * (lazyById) не деградирует с глубиной, а число round-trip к БД — доминирующая
     * составляющая времени выгрузки при удалённой БД.
     */
    public const DEFAULT_CHUNK_SIZE = 5000;

    protected ConnectionNames $connections;

    /**
     * @param array<int, string> $connections Список имён подключений, зарегистрированных в config/database.php.
     */
    public function __construct(array $connections)
    {
        $this->connections = new ConnectionNames($connections);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchUserDataFromAllDatabases(string $table, array $params): array
    {
        $filters = TableQueryParameters::fromArray($params);
        $allData = [];

        foreach ($this->connections->toArray() as $connectionName) {
            $data = $this->fetchUserData($table, $filters->toArray(), $connectionName);
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
        array|TableQueryParameters $params,
        string $connectionName,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ): Generator {
        $filters = $params instanceof TableQueryParameters
            ? $params
            : TableQueryParameters::fromArray($params);

        $connection = DB::connection($connectionName);
        $schema = $connection->getSchemaBuilder();

        if (!$schema->hasTable($table)) {
            return;
        }

        $columns = $this->getTableColumns($table, $connectionName);
        $field = $this->determineFilterField($table, $columns);

        if (!$field) {
            return;
        }

        $filter = $this->resolveFilter($filters, $field, $columns, $schema);

        if ($filter->isEmpty()) {
            return;
        }

        $query = $connection->table($table);
        $filter->applyTo($query, $field);

        $primaryKey = $this->determinePrimaryKey($table, $connectionName);

        // Keyset-пагинация (lazyById) по числовому PK — не деградирует с глубиной.
        // Для составных/нечисловых ключей keyset невозможен → chunked-fallback (lazy),
        // который под капотом использует OFFSET, но такие таблицы в scope невелики.
        $rows = $primaryKey !== null
            ? $query->lazyById($chunkSize, $primaryKey)
            : $query->orderBy($field)->lazy($chunkSize);

        foreach ($rows as $row) {
            yield (array) $row;
        }
    }

    public function getConnections(): array
    {
        return $this->connections->toArray();
    }

    /**
     * Выбирает критерий фильтрации таблицы: коррелированный подзапрос, если для поля
     * задана спецификация и её таблица доступна в подключении; иначе — литеральный список.
     */
    private function resolveFilter(
        TableQueryParameters $filters,
        string $field,
        array $columns,
        SchemaBuilder $schema
    ): TableFilter {
        $subquery = $filters->subqueryFor($field);

        if ($subquery !== null && $schema->hasTable($subquery->table())) {
            return new SubqueryFilter($subquery);
        }

        $values = $this->prepareParams(
            $field,
            $columns,
            $filters->valuesForWithFallback($field, 'account_id')->toArray(),
        );

        return new LiteralFilter($values);
    }
}
