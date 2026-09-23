<?php

declare(strict_types=1);

namespace App\Plan\Backup;

use App\Plan\Compiler\SelectorCompiler;
use App\Plan\Compiler\SelectorCompilerChain;
use App\Plan\CompiledUserDataPlan;
use App\Plan\ScopeValues;
use App\Plan\UserDataRule;
use Generator;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

/**
 * Готовит потоки строк для выгрузки бэкапа по тому же плану, по которому идёт удаление.
 *
 * Существует ради одного инварианта: снимок обязан содержать всё, что удаление унесёт.
 * Прежняя выгрузка определяла принадлежность строки по имени колонки и брала одно поле
 * на таблицу, поэтому связи через родителя, субсчёт или полиморфную пару она не видела —
 * такие строки удалялись и не возвращались из бэкапа никогда.
 *
 * Отдаёт ленивые генераторы: весь бэкап не живёт в памяти целиком, строки читаются
 * порциями по первичному ключу правила.
 */
final class PlanRowStreams
{
    public const DEFAULT_CHUNK_SIZE = 1000;

    private ConnectionResolverInterface $connections;

    private SelectorCompiler $compiler;

    private int $chunkSize;

    public function __construct(
        ConnectionResolverInterface $connections,
        SelectorCompiler $compiler,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ) {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('Размер порции должен быть положительным.');
        }

        $this->connections = $connections;
        $this->compiler = $compiler;
        $this->chunkSize = $chunkSize;
    }

    public static function default(
        ConnectionResolverInterface $connections,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ): self {
        return new self($connections, SelectorCompilerChain::default(), $chunkSize);
    }

    /**
     * Потоки строк в формате выгрузки: имя таблицы → список ленивых источников.
     *
     * Таблица, а не пара с подключением: файл бэкапа исторически ключуется именем, а
     * подключение при восстановлении определяет резолвер. Одноимённые таблицы двух
     * подключений поэтому дописываются в один ключ.
     *
     * Порядок — обратный порядку удаления, то есть родители раньше детей. Восстановление
     * читает файл сверху вниз и вставляет строки в том же порядке: дочерняя строка,
     * записанная раньше родителя, упирается во внешний ключ.
     *
     * @return array<string, array<int, iterable<int, array<string, mixed>>>>
     */
    public function forPlan(CompiledUserDataPlan $plan, ScopeValues $scope): array
    {
        $streams = [];

        foreach (array_reverse($plan->readable()) as $rule) {
            $table = $rule->tableRef()->table();

            $streams[$table][] = $this->rowsOf($rule, $scope);
        }

        return $streams;
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    private function rowsOf(UserDataRule $rule, ScopeValues $scope): Generator
    {
        $selector = $rule->selector();

        if ($selector === null) {
            return;
        }

        $connectionName = $rule->tableRef()->connection();
        $connection = $this->connections->connection($connectionName);
        $table = $rule->tableRef()->table();
        $primaryKey = $rule->primaryKey();
        $lastKey = null;

        // Курсор по ключу правила, а не OFFSET: у выгрузки те же требования к глубине,
        // что и у удаления, а ключ правила уже учитывает таблицы без числового PK
        // (`password_resets` — по email, `aton_portfolios_aggregated` — по assignment_id).
        while (true) {
            $query = $connection->table($table);

            $this->compiler->apply($query, $selector, $scope, $connectionName, $this->compiler);

            if ($lastKey !== null) {
                $query->where($primaryKey, '>', $lastKey);
            }

            $rows = $query
                ->orderBy($primaryKey)
                ->limit($this->chunkSize)
                ->get()
                ->all();

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                yield (array) $row;
            }

            $last = (array) $rows[count($rows) - 1];
            $lastKey = $last[$primaryKey] ?? null;

            if ($lastKey === null || count($rows) < $this->chunkSize) {
                return;
            }
        }
    }
}
