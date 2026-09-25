<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Execution;

use UserDataBackup\Plan\ScopeValues;
use UserDataBackup\Plan\UserDataRule;
use Illuminate\Database\ConnectionInterface;

/**
 * Удаляет строки правила порциями по первичному ключу.
 *
 * Удаление идёт по уже прочитанным ключам, а не повторным применением селектора: условие
 * с подзапросом к родителю после удаления части строк даёт другой результат, и повторный
 * `DELETE ... WHERE <selector>` пропустил бы строки, чей родитель уже исчез.
 */
final class DeleteRowsHandler implements TableActionHandler
{
    private RowChunkReader $reader;

    private KeysetCursor $keyset;

    public function __construct(RowChunkReader $reader, KeysetCursor $keyset)
    {
        $this->reader = $reader;
        $this->keyset = $keyset;
    }

    public function supports(UserDataRule $rule): bool
    {
        return $rule->action()->deletesRows();
    }

    public function handle(
        ConnectionInterface $connection,
        string $connectionName,
        UserDataRule $rule,
        ScopeValues $scope,
        int $chunkSize,
        bool $dryRun
    ): int {
        $affected = 0;
        $table = $rule->tableRef()->table();

        $chunks = $this->reader->chunks($connection, $connectionName, $rule, $scope, $chunkSize);

        foreach ($chunks as $keys) {
            if ($dryRun) {
                $affected += count($keys);

                continue;
            }

            $affected += $this->keyset
                ->matching($connection->table($table), $rule->cursorKey(), $keys)
                ->delete();
        }

        return $affected;
    }
}
