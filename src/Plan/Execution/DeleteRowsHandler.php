<?php

declare(strict_types=1);

namespace App\Plan\Execution;

use App\Plan\ScopeValues;
use App\Plan\UserDataRule;
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

    public function __construct(RowChunkReader $reader)
    {
        $this->reader = $reader;
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
        $primaryKey = $rule->primaryKey();

        $chunks = $this->reader->chunks($connection, $connectionName, $rule, $scope, $chunkSize);

        foreach ($chunks as $keys) {
            if ($dryRun) {
                $affected += count($keys);

                continue;
            }

            $affected += $connection->table($table)->whereIn($primaryKey, $keys)->delete();
        }

        return $affected;
    }
}
