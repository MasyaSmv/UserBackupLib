<?php

declare(strict_types=1);

namespace App\ValueObjects;

final class UserBackupCreateOptions
{
    public function __construct(
        private UserDataScope $scope,
        private ConnectionNames $connections
    ) {
    }

    /**
     * @param array<int, int|string> $accountIds Legacy-название параметра. В текущей интеграции ожидаются ids субсчетов.
     * @param array<int, int|string> $activeIds
     * @param array<int, string> $ignoredTables
     * @param array<int, string> $connections
     * @param FilterSubquery|null $activeIdSubquery Необязательный подзапрос для active_id (см. UserDataScope).
     */
    public static function fromLegacy(
        int $userId,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = [],
        array $connections = [],
        ?FilterSubquery $activeIdSubquery = null
    ): self {
        return new self(
            new UserDataScope($userId, $accountIds, $activeIds, $ignoredTables, $activeIdSubquery),
            new ConnectionNames($connections),
        );
    }

    public function scope(): UserDataScope
    {
        return $this->scope;
    }

    public function connections(): ConnectionNames
    {
        return $this->connections;
    }
}
