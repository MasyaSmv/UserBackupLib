<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\BackupProcessorInterface;
use App\Contracts\DatabaseServiceInterface;
use App\Contracts\FileStorageServiceInterface;
use App\Contracts\UserBackupServiceFactoryInterface;
use App\Contracts\UserBackupServiceInterface;
use App\Contracts\UserDataDeletionServiceInterface;
use App\Services\BackupProcessor;
use App\Services\DatabaseService;
use App\Services\FileStorageService;
use App\Services\UserDataDeletionService;
use App\ValueObjects\UserDataScope;

class UserBackupServiceProviderTest extends TestCase
{
    public function test_it_registers_expected_bindings(): void
    {
        $bindings = $this->app->getBindings();

        $this->assertArrayHasKey(\App\Contracts\DatabaseServiceInterface::class, $bindings);
        $this->assertArrayHasKey(\App\Contracts\BackupProcessorInterface::class, $bindings);
        $this->assertArrayHasKey(\App\Contracts\FileStorageServiceInterface::class, $bindings);
        $this->assertArrayHasKey(\App\Contracts\UserDataDeletionServiceInterface::class, $bindings);
        $this->assertArrayHasKey(\App\Contracts\UserBackupServiceFactoryInterface::class, $bindings);
        $this->assertArrayNotHasKey(\App\Contracts\UserBackupServiceInterface::class, $bindings);
    }

    public function test_it_resolves_infrastructure_services_from_container(): void
    {
        config()->set('database.connections', [
            'mysql' => [],
            'replica' => [],
        ]);
        config()->set('user-backup.connections', []);

        $databaseService = $this->app->make(DatabaseServiceInterface::class);
        $backupProcessor = $this->app->make(BackupProcessorInterface::class);
        $fileStorage = $this->app->make(FileStorageServiceInterface::class);
        $deletionService = $this->app->make(UserDataDeletionServiceInterface::class);

        $this->assertInstanceOf(DatabaseService::class, $databaseService);
        $this->assertSame(['mysql', 'replica'], $databaseService->getConnections());
        $this->assertInstanceOf(BackupProcessor::class, $backupProcessor);
        $this->assertInstanceOf(FileStorageService::class, $fileStorage);
        $this->assertInstanceOf(UserDataDeletionService::class, $deletionService);
    }

    public function test_it_prefers_explicit_package_connections_config(): void
    {
        config()->set('database.connections', [
            'mysql' => [],
            'replica' => [],
        ]);
        config()->set('user-backup.connections', ['analytics']);

        $databaseService = $this->app->make(DatabaseServiceInterface::class);

        $this->assertSame(['analytics'], $databaseService->getConnections());
    }

    public function test_it_resolves_backup_factory_and_creates_service_for_scope(): void
    {
        config()->set('database.connections', [
            'mysql' => [],
        ]);
        config()->set('user-backup.connections', ['mysql']);

        $factory = $this->app->make(UserBackupServiceFactoryInterface::class);
        $service = $factory->make(new UserDataScope(42, [1001], [501], ['logs']));

        $this->assertInstanceOf(UserBackupServiceInterface::class, $service);
    }

    public function test_it_creates_service_from_legacy_factory_arguments(): void
    {
        config()->set('database.connections', [
            'mysql' => [],
        ]);
        config()->set('user-backup.connections', ['mysql']);

        $factory = $this->app->make(UserBackupServiceFactoryInterface::class);
        $service = $factory->makeForUser(42, [1001], [501], ['logs']);

        $this->assertInstanceOf(UserBackupServiceInterface::class, $service);
    }
}
