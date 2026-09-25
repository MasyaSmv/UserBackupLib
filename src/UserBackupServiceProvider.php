<?php

declare(strict_types=1);

namespace App;

use App\Contracts\FileStorageServiceInterface;
use App\Services\FileStorageService;
use Illuminate\Support\ServiceProvider;

/**
 * Регистрирует потоковое хранилище файлов бэкапа.
 *
 * Отбор и удаление строк пользователя делает план (`App\Plan`), который собирает приложение.
 * Старый движок эвристического удаления по колонке `user_id` удалён (WS-3273): регистрировать
 * его значило оставлять новому потребителю возможность выбрать не тот API.
 */
class UserBackupServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FileStorageServiceInterface::class, FileStorageService::class);
    }
}
