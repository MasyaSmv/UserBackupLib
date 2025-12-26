<?php

declare(strict_types=1);

namespace Tests;

use App\UserBackupServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Базовый тест с конфигурацией Orchestra Testbench и in-memory SQLite.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            UserBackupServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.env', 'testing');
        $app['config']->set('app.key', 'base64:plXKgkljvvUYFmltUM6UU/o7Yj3z9VSned2+9uJ7ACs=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
