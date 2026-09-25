<?php

declare(strict_types=1);

namespace Tests;

use UserDataBackup\Contracts\FileStorageServiceInterface;
use UserDataBackup\Services\FileStorageService;

class UserBackupServiceProviderTest extends TestCase
{
    public function test_it_registers_file_storage_as_singleton(): void
    {
        $storage = $this->app->make(FileStorageServiceInterface::class);

        $this->assertInstanceOf(FileStorageService::class, $storage);
        $this->assertSame($storage, $this->app->make(FileStorageServiceInterface::class));
    }

    public function test_it_does_not_register_legacy_deletion_engine(): void
    {
        $bindings = array_keys($this->app->getBindings());

        $this->assertSame([], array_values(array_filter(
            $bindings,
            static fn (string $abstract): bool => str_contains($abstract, 'UserDataDeletionService')
                || str_contains($abstract, 'UserBackupServiceFactory')
                || str_contains($abstract, 'DatabaseServiceInterface')
                || str_contains($abstract, 'BackupProcessorInterface'),
        )));
    }
}
