<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Execution;

use UserDataBackup\Plan\ScopeValues;
use UserDataBackup\Plan\UserDataRule;
use Illuminate\Database\ConnectionInterface;

/**
 * Выполняет одно действие профиля над строками таблицы.
 *
 * Обработчик на каждое действие отдельный: анонимизация и отвязка добавляются новыми
 * классами, не трогая удаление.
 */
interface TableActionHandler
{
    public function supports(UserDataRule $rule): bool;

    /**
     * @param bool $dryRun Только посчитать строки, ничего не меняя.
     *
     * @return int Количество затронутых строк.
     */
    public function handle(
        ConnectionInterface $connection,
        string $connectionName,
        UserDataRule $rule,
        ScopeValues $scope,
        int $chunkSize,
        bool $dryRun
    ): int;
}
