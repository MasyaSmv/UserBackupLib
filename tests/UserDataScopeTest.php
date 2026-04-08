<?php

declare(strict_types=1);

namespace Tests;

use App\ValueObjects\UserDataScope;
use PHPUnit\Framework\TestCase;

class UserDataScopeTest extends TestCase
{
    public function test_it_normalizes_scope_and_detects_ignored_tables(): void
    {
        $scope = new UserDataScope(42, [1001, 1001], [501], ['users', 'users']);

        $this->assertSame(42, $scope->userId());
        $this->assertSame([1001], $scope->accountIds()->toArray());
        $this->assertSame([501], $scope->activeIds()->toArray());
        $this->assertSame(['users'], $scope->ignoredTables());
        $this->assertTrue($scope->isIgnoredTable('users'));
    }

    public function test_it_builds_backup_parameters_for_users_and_related_tables(): void
    {
        $scope = new UserDataScope(42, [1001], [501]);

        $this->assertSame(['id' => [42]], $scope->backupParametersForTable('users')->toArray());
        $this->assertSame([
            'user_id' => [42],
            'account_id' => [1001],
            'active_id' => [501],
        ], $scope->backupParametersForTable('positions')->toArray());
    }

    public function test_it_resolves_deletion_values_for_supported_fields(): void
    {
        $scope = new UserDataScope(42, [1001], [501]);

        $this->assertSame([42], $scope->deletionValuesFor('users', 'id')->toArray());
        $this->assertSame([1001], $scope->deletionValuesFor('transactions', 'account_id')->toArray());
        $this->assertSame([501], $scope->deletionValuesFor('assets', 'active_id')->toArray());
        $this->assertSame([1001], $scope->deletionValuesFor('user_subaccounts', 'id')->toArray());
        $this->assertSame([], $scope->deletionValuesFor('logs', 'id')->toArray());
    }
}
