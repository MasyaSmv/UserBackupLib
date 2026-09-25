<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Execution;

use UserDataBackup\Plan\Compiler\SelectorCompiler;
use UserDataBackup\Plan\ScopeValues;
use UserDataBackup\Plan\UserDataRule;
use Generator;
use Illuminate\Database\ConnectionInterface;

/**
 * Читает ключи строк правила порциями, двигаясь по возрастанию ключа курсора.
 *
 * Именно порции строк, а не порции идентификаторов скоупа: `WHERE user_id IN (2275)` —
 * один элемент в списке, но сотни тысяч строк в таблице, и удаление такой выборки одним
 * запросом держит блокировки до конца транзакции.
 *
 * Курсор по ключу, а не `OFFSET`: удаление сдвигает строки, и постраничный обход с
 * offset пропускал бы каждую вторую порцию. Ключ уникальный и может быть составным —
 * иначе граница порции внутри группы одинаковых значений теряла бы строки (WS-3101).
 */
final class RowChunkReader
{
    private SelectorCompiler $compiler;

    private KeysetCursor $keyset;

    public function __construct(SelectorCompiler $compiler, KeysetCursor $keyset)
    {
        $this->compiler = $compiler;
        $this->keyset = $keyset;
    }

    /**
     * @return Generator<int, array<int, array<string, mixed>>> Порции значений ключа курсора.
     *
     * @throws \UserDataBackup\Plan\Exceptions\NullCursorValueException
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

        $key = $rule->cursorKey();
        $last = null;

        while (true) {
            $query = $connection->table($rule->tableRef()->table());

            $this->compiler->apply($query, $selector, $scope, $connectionName, $this->compiler);

            if ($last !== null) {
                $this->keyset->after($query, $key, $last);
            }

            $rows = $this->keyset->order($query, $key)
                ->limit($chunkSize)
                ->get($key->columns())
                ->all();

            if ($rows === []) {
                return;
            }

            $keys = array_map(
                static fn ($row): array => $key->valuesOf((array) $row, $rule->tableRef()),
                $rows,
            );

            yield $keys;

            $last = $keys[count($keys) - 1];

            if (count($keys) < $chunkSize) {
                return;
            }
        }
    }
}
