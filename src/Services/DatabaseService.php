<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DatabaseServiceInterface;
use App\Contracts\TableFilter;
use App\Services\Concerns\TableFiltering;
use App\Services\Internal\ConnectionSchema;
use App\ValueObjects\ConnectionNames;
use App\ValueObjects\LiteralFilter;
use App\ValueObjects\SubqueryFilter;
use App\ValueObjects\TableQueryParameters;
use Generator;
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
     * Снимки схем подключений, загружаемые один раз (таблицы/колонки/PK за один запрос).
     *
     * @var array<string, ConnectionSchema>
     */
    private array $schemas = [];

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

        $schema = $this->schema($connectionName);

        if (!$schema->hasTable($table)) {
            return;
        }

        $columns = $schema->columns($table);
        $field = $this->determineFilterField($table, $columns);

        if (!$field) {
            return;
        }

        $filter = $this->resolveFilter($filters, $field, $columns, $schema);

        if ($filter->isEmpty()) {
            return;
        }

        $query = DB::connection($connectionName)->table($table);
        $filter->applyTo($query, $field);

        $primaryKey = $schema->numericPrimaryKey($table);

        // Keyset-пагинация (lazyById) по числовому PK — не деградирует с глубиной.
        // Для составных/нечисловых ключей keyset невозможен → chunked-fallback (lazy).
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
     * Имена таблиц подключения (из предзагруженного снимка схемы, без round-trip).
     *
     * @return array<int, string>
     */
    public function getTables(string $connectionName): array
    {
        return $this->schema($connectionName)->tables();
    }

    public function hasTable(string $connectionName, string $table): bool
    {
        return $this->schema($connectionName)->hasTable($table);
    }

    /**
     * Ленивая загрузка снимка схемы подключения (один запрос на подключение).
     */
    private function schema(string $connectionName): ConnectionSchema
    {
        return $this->schemas[$connectionName]
            ??= ConnectionSchema::load(DB::connection($connectionName));
    }

    /**
     * Выбирает критерий фильтрации таблицы: коррелированный подзапрос, если для поля
     * задана спецификация и её таблица доступна в подключении; иначе — литеральный список.
     */
    private function resolveFilter(
        TableQueryParameters $filters,
        string $field,
        array $columns,
        ConnectionSchema $schema
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
