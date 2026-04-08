<?php

declare(strict_types=1);

namespace Tests;

class UserBackupServiceProviderTest extends TestCase
{
    public function test_it_registers_expected_bindings(): void
    {
        $bindings = $this->app->getBindings();

        $this->assertArrayHasKey(\App\Contracts\DatabaseServiceInterface::class, $bindings);
        $this->assertArrayHasKey(\App\Contracts\BackupProcessorInterface::class, $bindings);
        $this->assertArrayHasKey(\App\Contracts\FileStorageServiceInterface::class, $bindings);
        $this->assertArrayHasKey(\App\Contracts\UserDataDeletionServiceInterface::class, $bindings);
        $this->assertArrayHasKey(\App\Contracts\UserBackupServiceInterface::class, $bindings);
    }
}
