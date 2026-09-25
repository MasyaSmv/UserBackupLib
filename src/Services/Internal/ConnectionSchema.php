<?php

declare(strict_types=1);

namespace UserDataBackup\Services\Internal;

use Illuminate\Database\ConnectionInterface;

/**
 * Снимок схемы одного подключения, загружаемый ЗА ОДИН запрос.
 *
 * Мотивация: при выгрузке бэкапа библиотека обходит все таблицы БД и для каждой раньше
 * делала несколько round-trip (hasTable + список колонок + первичный ключ). На удалённой
 * БД с сетевой латентностью это давало десятки секунд «налога» на пустых таблицах.
 * Здесь все имена таблиц, их колонки, типы и первичные ключи читаются одним запросом к
 * information_schema и дальше отдаются из памяти без обращений к БД.
 */
final class ConnectionSchema
{
    /**
     * @var array<string, array<string, string>> table => [column => dataType]
     */
    private array $columns;

    /**
     * @var array<string, string> table => primaryKeyColumn (только одиночный числовой PK)
     */
    private array $numericPrimaryKeys;

    /**
     * @var array<string, array<string, bool>> table => [column => допускает NULL]
     */
    private array $nullable;

    /**
     * @var array<string, array<int, array<int, string>>> table => [колонки уникального индекса или PK]
     */
    private array $uniqueKeys;

    private const NUMERIC_TYPES = ['int', 'integer', 'bigint', 'mediumint', 'smallint', 'tinyint'];

    /**
     * @param array<string, array<string, string>> $columns
     * @param array<string, string>                $numericPrimaryKeys
     * @param array<string, array<string, bool>>   $nullable
     * @param array<string, array<int, array<int, string>>> $uniqueKeys
     */
    private function __construct(array $columns, array $numericPrimaryKeys, array $nullable, array $uniqueKeys)
    {
        $this->columns = $columns;
        $this->numericPrimaryKeys = $numericPrimaryKeys;
        $this->nullable = $nullable;
        $this->uniqueKeys = $uniqueKeys;
    }

    public static function load(ConnectionInterface $connection): self
    {
        $driver = method_exists($connection, 'getDriverName') ? $connection->getDriverName() : 'mysql';

        return $driver === 'sqlite'
            ? self::loadSqlite($connection)
            : self::loadInformationSchema($connection);
    }

    public function hasTable(string $table): bool
    {
        return isset($this->columns[$table]);
    }

    /**
     * @return array<int, string>
     */
    public function tables(): array
    {
        return array_keys($this->columns);
    }

    /**
     * @return array<int, string>
     */
    public function columns(string $table): array
    {
        return array_keys($this->columns[$table] ?? []);
    }

    /**
     * Имя одиночного числового первичного ключа (пригодного для keyset) либо null.
     */
    /**
     * Колонка допускает NULL. Для отсутствующей колонки — false: обнулить её нельзя.
     */
    public function isNullable(string $table, string $column): bool
    {
        return $this->nullable[$table][$column] ?? false;
    }

    /**
     * Набор колонок однозначно определяет строку: в него целиком входит первичный ключ
     * или один из уникальных индексов таблицы.
     *
     * @param array<int, string> $columns
     */
    public function isUniqueWithin(string $table, array $columns): bool
    {
        foreach ($this->uniqueKeys[$table] ?? [] as $uniqueKey) {
            if (array_diff($uniqueKey, $columns) === []) {
                return true;
            }
        }

        return false;
    }

    public function numericPrimaryKey(string $table): ?string
    {
        return $this->numericPrimaryKeys[$table] ?? null;
    }

    /**
     * MySQL/MariaDB: одна выборка по всей схеме — таблицы, колонки, типы и PRIMARY-колонки.
     */
    private static function loadInformationSchema(ConnectionInterface $connection): self
    {
        $database = $connection->getDatabaseName();

        $rows = $connection->select(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_KEY, IS_NULLABLE '
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? '
            . 'ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$database],
        );

        $columns = [];
        $primaryColumns = [];
        $nullable = [];

        foreach ($rows as $row) {
            $table = (string) $row->TABLE_NAME;
            $column = (string) $row->COLUMN_NAME;
            $type = (string) $row->DATA_TYPE;

            $columns[$table][$column] = $type;
            $nullable[$table][$column] = (string) $row->IS_NULLABLE === 'YES';

            if ((string) $row->COLUMN_KEY === 'PRI') {
                $primaryColumns[$table][] = [$column, $type];
            }
        }

        return new self(
            $columns,
            self::resolveNumericPrimaryKeys($primaryColumns),
            $nullable,
            self::loadInformationSchemaUniqueKeys($connection, $database),
        );
    }

    /**
     * Первичный ключ и уникальные индексы: колонки в порядке индекса.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    private static function loadInformationSchemaUniqueKeys(ConnectionInterface $connection, string $database): array
    {
        $rows = $connection->select(
            'SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME '
            . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND NON_UNIQUE = 0 '
            . 'ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            [$database],
        );

        $indexes = [];

        foreach ($rows as $row) {
            $indexes[(string) $row->TABLE_NAME][(string) $row->INDEX_NAME][] = (string) $row->COLUMN_NAME;
        }

        return array_map('array_values', $indexes);
    }

    /**
     * SQLite (тесты): список таблиц из sqlite_master + PRAGMA на каждую.
     */
    private static function loadSqlite(ConnectionInterface $connection): self
    {
        $tables = $connection->select(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
        );

        $columns = [];
        $primaryColumns = [];
        $nullable = [];

        foreach ($tables as $tableRow) {
            $table = (string) $tableRow->name;
            $info = $connection->select('PRAGMA table_info(' . $connection->getPdo()->quote($table) . ')');

            foreach ($info as $column) {
                $columns[$table][(string) $column->name] = (string) $column->type;
                $nullable[$table][(string) $column->name] = (int) $column->notnull === 0 && (int) $column->pk === 0;

                if ((int) $column->pk > 0) {
                    $primaryColumns[$table][] = [(string) $column->name, (string) $column->type];
                }
            }
        }

        return new self(
            $columns,
            self::resolveNumericPrimaryKeys($primaryColumns),
            $nullable,
            self::loadSqliteUniqueKeys($connection, array_keys($columns), $primaryColumns),
        );
    }

    /**
     * Первичный ключ из `table_info` и уникальные индексы из `index_list`.
     *
     * Первичный ключ берётся отдельно: у `INTEGER PRIMARY KEY` в SQLite нет записи в
     * `index_list`.
     *
     * @param array<int, string>                                      $tables
     * @param array<string, array<int, array{0: string, 1: string}>> $primaryColumns
     *
     * @return array<string, array<int, array<int, string>>>
     */
    private static function loadSqliteUniqueKeys(ConnectionInterface $connection, array $tables, array $primaryColumns): array
    {
        $pdo = $connection->getPdo();
        $keys = [];

        foreach ($tables as $table) {
            if (isset($primaryColumns[$table])) {
                $keys[$table][] = array_column($primaryColumns[$table], 0);
            }

            foreach ($connection->select('PRAGMA index_list(' . $pdo->quote($table) . ')') as $index) {
                if ((int) $index->unique !== 1) {
                    continue;
                }

                $info = $connection->select('PRAGMA index_info(' . $pdo->quote((string) $index->name) . ')');

                usort($info, static fn ($a, $b): int => (int) $a->seqno <=> (int) $b->seqno);

                $keys[$table][] = array_map(static fn ($column): string => (string) $column->name, $info);
            }
        }

        return $keys;
    }

    /**
     * Оставляет только таблицы с ровно одним числовым PK — для остальных keyset невозможен.
     *
     * @param array<string, array<int, array{0: string, 1: string}>> $primaryColumns
     * @return array<string, string>
     */
    private static function resolveNumericPrimaryKeys(array $primaryColumns): array
    {
        $result = [];

        foreach ($primaryColumns as $table => $cols) {
            if (count($cols) !== 1) {
                continue;
            }

            [$name, $type] = $cols[0];

            if (self::isNumericType($type)) {
                $result[$table] = $name;
            }
        }

        return $result;
    }

    private static function isNumericType(string $type): bool
    {
        // "bigint(20) unsigned" -> "bigint".
        $normalized = strtolower(trim($type));
        $normalized = preg_replace('/[\s(].*$/', '', $normalized) ?? $normalized;

        return in_array($normalized, self::NUMERIC_TYPES, true);
    }
}
