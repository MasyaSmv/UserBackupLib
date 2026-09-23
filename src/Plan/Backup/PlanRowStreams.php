<?php

declare(strict_types=1);

namespace App\Plan\Backup;

use App\Plan\Compiler\SelectorCompiler;
use App\Plan\Compiler\SelectorCompilerChain;
use App\Plan\CompiledUserDataPlan;
use App\Plan\Execution\KeysetCursor;
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
 * порциями по ключу курсора правила — тем же, по которому режет удаление.
 */
final class PlanRowStreams
{
    public const DEFAULT_CHUNK_SIZE = 1000;

    private ConnectionResolverInterface $connections;

    private SelectorCompiler $compiler;

    private KeysetCursor $keyset;

    private int $chunkSize;

    public function __construct(
        ConnectionResolverInterface $connections,
        SelectorCompiler $compiler,
        KeysetCursor $keyset,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ) {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('Размер порции должен быть положительным.');
        }

        $this->connections = $connections;
        $this->compiler = $compiler;
        $this->keyset = $keyset;
        $this->chunkSize = $chunkSize;
    }

    public static function default(
        ConnectionResolverInterface $connections,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ): self {
        return new self($connections, SelectorCompilerChain::default(), new KeysetCursor(), $chunkSize);
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
        $key = $rule->cursorKey();
        $last = null;

        // Курсор по уникальному ключу правила, а не OFFSET и не по первой колонке: у
        // `aton_portfolios_aggregated` на одну привязку приходится до 69 тысяч строк, и
        // курсор по `assignment_id` оставлял в бэкапе одну порцию из них (WS-3101).
        while (true) {
            $query = $connection->table($table);

            $this->compiler->apply($query, $selector, $scope, $connectionName, $this->compiler);

            if ($last !== null) {
                $this->keyset->after($query, $key, $last);
            }

            $rows = $this->keyset->order($query, $key)
                ->limit($this->chunkSize)
                ->get()
                ->all();

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                yield (array) $row;
            }

            $last = $key->valuesOf((array) $rows[count($rows) - 1], $rule->tableRef());

            if (count($rows) < $this->chunkSize) {
                return;
            }
        }
    }
}
