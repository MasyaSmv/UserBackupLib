<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\BackupProcessorInterface;
use App\Contracts\DatabaseServiceInterface;
use App\Contracts\FileStorageServiceInterface;
use App\Contracts\UserBackupServiceFactoryInterface;
use App\Contracts\UserBackupServiceInterface;
use App\ValueObjects\UserDataScope;

final class UserBackupServiceFactory implements UserBackupServiceFactoryInterface
{
    public function __construct(
        private DatabaseServiceInterface $databaseService,
        private BackupProcessorInterface $backupProcessor,
        private FileStorageServiceInterface $fileStorageService
    ) {
    }

    public function make(UserDataScope $scope): UserBackupServiceInterface
    {
        return new UserBackupService(
            $scope->userId(),
            $this->databaseService,
            $this->backupProcessor,
            $this->fileStorageService,
            $scope->accountIds()->toArray(),
            $scope->activeIds()->toArray(),
            $scope->ignoredTables(),
        );
    }

    public function makeForUser(
        int $userId,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = []
    ): UserBackupServiceInterface {
        return $this->make(new UserDataScope($userId, $accountIds, $activeIds, $ignoredTables));
    }
}
