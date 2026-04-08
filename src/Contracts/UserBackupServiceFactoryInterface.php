<?php

declare(strict_types=1);

namespace App\Contracts;

use App\ValueObjects\UserDataScope;

interface UserBackupServiceFactoryInterface
{
    public function make(UserDataScope $scope): UserBackupServiceInterface;

    /**
     * @param array<int, int|string> $accountIds
     * @param array<int, int|string> $activeIds
     * @param array<int, string> $ignoredTables
     */
    public function makeForUser(
        int $userId,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = []
    ): UserBackupServiceInterface;
}
