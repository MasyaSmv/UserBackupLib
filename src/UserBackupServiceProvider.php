<?php

declare(strict_types=1);

namespace UserDataBackup;

use UserDataBackup\Contracts\BackupStreamCompressor;
use UserDataBackup\Contracts\BackupStreamDecoder;
use UserDataBackup\Contracts\FileStorageServiceInterface;
use UserDataBackup\Services\Compression\SignatureAwareStreamDecoder;
use UserDataBackup\Services\Compression\ZlibStreamCompressor;
use UserDataBackup\Services\FileStorageService;
use Illuminate\Support\ServiceProvider;

/**
 * Регистрирует потоковое хранилище файлов бэкапа и сжатие его потока (WS-3280).
 *
 * Отбор и удаление строк пользователя делает план (`UserDataBackup\Plan`), который собирает приложение.
 * Старый движок эвристического удаления по колонке `user_id` удалён (WS-3273): регистрировать
 * его значило оставлять новому потребителю возможность выбрать не тот API.
 */
class UserBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BackupStreamCompressor::class, ZlibStreamCompressor::class);
        $this->app->singleton(BackupStreamDecoder::class, SignatureAwareStreamDecoder::class);
        $this->app->singleton(FileStorageServiceInterface::class, FileStorageService::class);
    }
}
