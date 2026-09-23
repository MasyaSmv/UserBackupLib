<?php

declare(strict_types=1);

namespace App\Plan\Execution;

use App\Plan\ScopeValues;
use App\Plan\TableAction;
use App\Plan\UserDataRule;
use Illuminate\Database\ConnectionInterface;

/**
 * Обнуляет ссылку на пользователя, оставляя строку на месте.
 *
 * Нужен там, где на другом конце связи живёт второй пользователь: у связи двух клиентов
 * или коллективной группы удаление строки целиком испортило бы данные того, кого никто
 * не просил трогать.
 */
final class DetachRowsHandler implements TableActionHandler
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
        return $rule->action()->value() === TableAction::DETACH;
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
        $update = array_fill_keys($rule->detachColumns(), null);

        $chunks = $this->reader->chunks($connection, $connectionName, $rule, $scope, $chunkSize);

        foreach ($chunks as $keys) {
            if ($dryRun) {
                $affected += count($keys);

                continue;
            }

            $affected += $this->keyset
                ->matching($connection->table($table), $rule->cursorKey(), $keys)
                ->update($update);
        }

        return $affected;
    }
}
