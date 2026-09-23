<?php

declare(strict_types=1);

namespace App\Plan\Execution;

use App\Plan\Compiler\SelectorCompiler;
use App\Plan\ScopeValues;
use App\Plan\UserDataRule;
use Generator;
use Illuminate\Database\ConnectionInterface;

/**
 * Читает первичные ключи строк правила порциями, двигаясь по возрастанию ключа.
 *
 * Именно порции строк, а не порции идентификаторов скоупа: `WHERE user_id IN (2275)` —
 * один элемент в списке, но сотни тысяч строк в таблице, и удаление такой выборки одним
 * запросом держит блокировки до конца транзакции.
 *
 * Курсор по ключу, а не `OFFSET`: удаление сдвигает строки, и постраничный обход с
 * offset пропускал бы каждую вторую порцию.
 */
final class RowChunkReader
{
    private SelectorCompiler $compiler;

    public function __construct(SelectorCompiler $compiler)
    {
        $this->compiler = $compiler;
    }

    /**
     * @return Generator<int, array<int, int|string>> Порции значений первичного ключа.
     */
    public function chunks(
        ConnectionInterface $connection,
        string $connectionName,
        UserDataRule $rule,
        ScopeValues $scope,
        int $chunkSize
    ): Generator {
        $selector = $rule->selector();

        if ($selector === null || $chunkSize < 1) {
            return;
        }

        $primaryKey = $rule->primaryKey();
        $lastKey = null;

        while (true) {
            $query = $connection->table($rule->tableRef()->table());

            $this->compiler->apply($query, $selector, $scope, $connectionName, $this->compiler);

            if ($lastKey !== null) {
                $query->where($primaryKey, '>', $lastKey);
            }

            $keys = $query
                ->orderBy($primaryKey)
                ->limit($chunkSize)
                ->pluck($primaryKey)
                ->all();

            if ($keys === []) {
                return;
            }

            yield $keys;

            $lastKey = $keys[count($keys) - 1];

            if (count($keys) < $chunkSize) {
                return;
            }
        }
    }
}
