<?php

declare(strict_types=1);

namespace App;

use App\Contracts\BackupProcessorInterface;
use App\Contracts\DatabaseServiceInterface;
use App\Contracts\FileStorageServiceInterface;
use App\Contracts\UserBackupServiceFactoryInterface;
use App\Contracts\UserBackupServiceInterface;
use App\Contracts\UserDataDeletionServiceInterface;
use App\Services\BackupProcessor;
use App\Services\DatabaseService;
use App\Services\FileStorageService;
use App\Services\UserBackupService;
use App\Services\UserBackupServiceFactory;
use App\Services\UserDataDeletionService;
use Illuminate\Support\ServiceProvider;

class UserBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/user-backup.php', 'user-backup');

        $this->app->singleton(DatabaseServiceInterface::class, function ($app) {
            $connections = (array) $app['config']->get('user-backup.connections', []);

            if ($connections === []) {
                $connections = array_keys((array) $app['config']->get('database.connections', []));
            }

            return new DatabaseService($connections);
        });

        $this->app->singleton(BackupProcessorInterface::class, BackupProcessor::class);
        $this->app->singleton(FileStorageServiceInterface::class, FileStorageService::class);
        $this->app->singleton(UserDataDeletionServiceInterface::class, function ($app) {
            return new UserDataDeletionService($app->make(DatabaseServiceInterface::class));
        });
        $this->app->singleton(UserBackupServiceFactoryInterface::class, function ($app) {
            return new UserBackupServiceFactory(
                $app->make(DatabaseServiceInterface::class),
                $app->make(BackupProcessorInterface::class),
                $app->make(FileStorageServiceInterface::class),
            );
        });
    }
}
