<?php

declare(strict_types=1);

namespace App;

use App\Contracts\BackupProcessorInterface;
use App\Contracts\DatabaseServiceInterface;
use App\Contracts\FileStorageServiceInterface;
use App\Contracts\UserBackupServiceInterface;
use App\Contracts\UserDataDeletionServiceInterface;
use App\Services\BackupProcessor;
use App\Services\DatabaseService;
use App\Services\FileStorageService;
use App\Services\UserBackupService;
use App\Services\UserDataDeletionService;
use Illuminate\Support\ServiceProvider;

class UserBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DatabaseServiceInterface::class, DatabaseService::class);
        $this->app->bind(BackupProcessorInterface::class, BackupProcessor::class);
        $this->app->bind(FileStorageServiceInterface::class, FileStorageService::class);
        $this->app->bind(UserDataDeletionServiceInterface::class, UserDataDeletionService::class);
        $this->app->bind(UserBackupServiceInterface::class, UserBackupService::class);
    }
}
