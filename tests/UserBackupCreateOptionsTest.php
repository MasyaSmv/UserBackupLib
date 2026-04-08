<?php

declare(strict_types=1);

namespace Tests;

use App\ValueObjects\ConnectionNames;
use App\ValueObjects\UserBackupCreateOptions;
use App\ValueObjects\UserDataScope;
use PHPUnit\Framework\TestCase;

class UserBackupCreateOptionsTest extends TestCase
{
    public function test_it_builds_scope_and_connection_objects_from_legacy_input(): void
    {
        $options = UserBackupCreateOptions::fromLegacy(
            userId: 42,
            accountIds: [1001, 1001],
            activeIds: [501],
            ignoredTables: ['logs', 'logs'],
            connections: ['mysql', '', 'replica', 'mysql'],
        );

        $this->assertInstanceOf(UserDataScope::class, $options->scope());
        $this->assertInstanceOf(ConnectionNames::class, $options->connections());
        $this->assertSame(42, $options->scope()->userId());
        $this->assertSame([1001], $options->scope()->subaccountIds()->toArray());
        $this->assertSame([501], $options->scope()->activeIds()->toArray());
        $this->assertSame(['logs'], $options->scope()->ignoredTables());
        $this->assertSame(['mysql', 'replica'], $options->connections()->toArray());
    }
}
