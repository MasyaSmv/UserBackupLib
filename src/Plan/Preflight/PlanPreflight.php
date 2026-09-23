<?php

declare(strict_types=1);

namespace App\Plan\Preflight;

use App\Plan\CompiledUserDataPlan;
use App\Plan\Exceptions\SchemaMismatchException;
use App\Plan\Exceptions\UncheckedConnectionException;
use App\Plan\TableRef;
use App\Plan\UserDataRule;
use App\Services\Internal\ConnectionSchema;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Сверяет план с реальной схемой до начала операции.
 *
 * Проверка именно перед стартом, а не по ходу: удаление идёт таблица за таблицей, и
 * падение на середине оставило бы пользователя с половиной удалённых данных. Схема
 * читается снимком — по одному запросу на подключение.
 */
final class PlanPreflight
{
    private ConnectionResolverInterface $connections;

    public function __construct(ConnectionResolverInterface $connections)
    {
        $this->connections = $connections;
    }

    /**
     * @param array<int, string> $connectionNames Подключения, которые обслуживает план.
     *
     * Проверка асимметрична намеренно. Таблица схемы без правила — блокирующая ошибка:
     * именно так данные и оставались в базе после удаления владельца. Правило для
     * таблицы, которой в этой схеме нет, ошибкой не считается: набор таблиц отличается
     * между окружениями, а удалять там всё равно нечего — такие случаи попадают в отчёт.
     *
     * Отсутствующим считается только то, чего нет в проверенной схеме. Правило на
     * подключении, которое не передали, — ошибка вызова, а не различие окружений.
     *
     * @throws UncheckedConnectionException                 Правило на непроверенном подключении.
     * @throws \App\Plan\Exceptions\PlanIncompleteException Схема содержит таблицу без правила.
     * @throws SchemaMismatchException                      Правило расходится со схемой.
     */
    public function check(CompiledUserDataPlan $plan, array $connectionNames): PreflightReport
    {
        $this->assertConnectionsChecked($plan, $connectionNames);

        $schemas = $this->loadSchemas($connectionNames);

        $plan->assertCovers($this->tablesInSchema($schemas));

        $problems = [];
        $missingTables = [];

        foreach ($plan->rules() as $rule) {
            $tableKey = $rule->tableRef()->key();

            if (!$this->tableExists($rule, $schemas)) {
                $missingTables[] = $tableKey;

                continue;
            }

            foreach ($this->problemsFor($rule, $schemas) as $problem) {
                $problems[] = $problem;
            }
        }

        if ($problems !== []) {
            throw new SchemaMismatchException($problems);
        }

        return new PreflightReport($missingTables);
    }

    /**
     * @param array<int, string> $connectionNames
     *
     * @throws UncheckedConnectionException
     */
    private function assertConnectionsChecked(CompiledUserDataPlan $plan, array $connectionNames): void
    {
        $unchecked = [];
        $tables = [];

        foreach ($plan->rules() as $rule) {
            $connection = $rule->tableRef()->connection();

            if (!in_array($connection, $connectionNames, true)) {
                $unchecked[$connection] = $connection;
                $tables[] = $rule->tableRef()->key();
            }
        }

        if ($unchecked !== []) {
            throw new UncheckedConnectionException(array_values($unchecked), $tables);
        }
    }

    /**
     * @param array<string, ConnectionSchema> $schemas
     */
    private function tableExists(UserDataRule $rule, array $schemas): bool
    {
        $tableRef = $rule->tableRef();
        $schema = $schemas[$tableRef->connection()] ?? null;

        return $schema !== null && $schema->hasTable($tableRef->table());
    }

    /**
     * @param array<int, string> $connectionNames
     *
     * @return array<string, ConnectionSchema>
     */
    private function loadSchemas(array $connectionNames): array
    {
        $schemas = [];

        foreach ($connectionNames as $name) {
            $schemas[$name] = ConnectionSchema::load($this->connections->connection($name));
        }

        return $schemas;
    }

    /**
     * @param array<string, ConnectionSchema> $schemas
     *
     * @return array<int, TableRef>
     */
    private function tablesInSchema(array $schemas): array
    {
        $refs = [];

        foreach ($schemas as $connection => $schema) {
            foreach ($schema->tables() as $table) {
                $refs[] = new TableRef($connection, $table);
            }
        }

        return $refs;
    }

    /**
     * Расхождения одного правила со схемой.
     *
     * @param array<string, ConnectionSchema> $schemas
     *
     * @return array<int, string>
     */
    private function problemsFor(UserDataRule $rule, array $schemas): array
    {
        $tableRef = $rule->tableRef();
        $schema = $schemas[$tableRef->connection()];

        $problems = array_merge(
            $this->missingColumns($rule, $schema),
            $this->notNullableDetachColumns($rule, $schema),
        );

        foreach ($rule->parents() as $parentKey => $parent) {
            $parentSchema = $schemas[$parent->connection()] ?? null;

            // Родитель отсутствует — дочернее правило не сможет отобрать строки, а не
            // просто ничего не найдёт: это ошибка описания, а не различие окружений.
            if ($parentSchema === null || !$parentSchema->hasTable($parent->table())) {
                $problems[] = $tableRef->key() . ' ссылается на отсутствующего родителя ' . $parentKey;
            }
        }

        return $problems;
    }

    /**
     * Колонки `detach`, которые нельзя обнулить.
     *
     * Ограничение NOT NULL выстрелило бы на UPDATE, когда предыдущие правила уже удалили
     * свои строки, поэтому ловим его здесь. Отсутствующую колонку уже отметил
     * `missingColumns`, второй раз её не называем.
     *
     * @return array<int, string>
     */
    private function notNullableDetachColumns(UserDataRule $rule, ConnectionSchema $schema): array
    {
        $table = $rule->tableRef()->table();
        $existing = $schema->columns($table);
        $problems = [];

        foreach ($rule->detachColumns() as $column) {
            if (in_array($column, $existing, true) && !$schema->isNullable($table, $column)) {
                $problems[] = 'в ' . $rule->tableRef()->key() . ' колонка ' . $column . ' не допускает NULL';
            }
        }

        return $problems;
    }

    /**
     * @return array<int, string>
     */
    private function missingColumns(UserDataRule $rule, ConnectionSchema $schema): array
    {
        $existing = $schema->columns($rule->tableRef()->table());
        $problems = [];

        foreach ($rule->requiredColumns() as $column) {
            if (!in_array($column, $existing, true)) {
                $problems[] = 'в ' . $rule->tableRef()->key() . ' нет колонки ' . $column;
            }
        }

        return $problems;
    }
}
