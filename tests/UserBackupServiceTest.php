<?php

declare(strict_types=1);

namespace Tests;

use App\Services\BackupProcessor;
use App\Services\DatabaseService;
use App\Services\FileStorageService;
use App\Services\UserBackupService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * @allure.suite("UserBackup")
 * @allure.epic("UserBackupLib")
 * @allure.owner("backend")
 * @allure.lead("backend")
 * @allure.layer("integration")
 * @allure.tag("backup", "stream", "encryption")
 */
class UserBackupServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('testing')->dropIfExists('positions');
        Schema::connection('testing')->dropIfExists('transactions');
        Schema::connection('testing')->dropIfExists('users');

        Schema::connection('testing')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection('testing')->create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->decimal('amount', 10, 2);
        });

        Schema::connection('testing')->create('positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('active_id');
            $table->string('symbol');
        });

        $this->seedData();
    }

    protected function tearDown(): void
    {
        $this->cleanupBackups();
        parent::tearDown();
    }

    /**
     * @allure.title("Стримовое сохранение бэкапа без шифрования")
     * @allure.description("Проверяем, что данные выгружаются чанками и сохраняются в JSON без полной загрузки в память.")
     * @allure.severity(critical)
     * @allure.story("Бэкап данных пользователя")
     */
    public function test_backup_streams_and_saves_without_encryption(): void
    {
        $service = $this->makeBackupService(
            userId: 1,
            accountIds: [1001],
            activeIds: [501],
        );

        $service->fetchAllUserData();
        $path = $service->saveBackupToFile(false);

        $this->assertFileExists($path);

        $payload = json_decode(file_get_contents($path), true);

        $this->assertArrayHasKey('users', $payload);
        $this->assertCount(1, $payload['users']);
        $this->assertSame('Alice', $payload['users'][0]['name']);

        $this->assertArrayHasKey('transactions', $payload);
        $this->assertCount(2, $payload['transactions']);

        $this->assertArrayHasKey('positions', $payload);
        $this->assertCount(1, $payload['positions']);
        $this->assertSame('AAPL', $payload['positions'][0]['symbol']);
    }

    /**
     * @allure.title("Стримовое сохранение бэкапа с шифрованием")
     * @allure.description("Данные шифруются построчно, что позволяет обрабатывать большие объёмы без переполнения памяти.")
     * @allure.severity(critical)
     * @allure.story("Бэкап данных пользователя")
     */
    public function test_backup_streams_and_encrypts(): void
    {
        $service = $this->makeBackupService(
            userId: 1,
            accountIds: [1001],
            activeIds: [501],
        );

        $service->fetchAllUserData();
        $path = $service->saveBackupToFile(true);

        $this->assertFileExists($path);
        $this->assertSame('enc', pathinfo($path, PATHINFO_EXTENSION));

        $data = FileStorageService::decryptFile($path);

        $this->assertArrayHasKey('users', $data);
        $this->assertSame('Alice', $data['users'][0]['name']);
    }

    private function makeBackupService(int $userId, array $accountIds, array $activeIds): UserBackupService
    {
        $databaseService = new DatabaseService(['testing']);
        $backupProcessor = new BackupProcessor();
        $fileStorageService = new FileStorageService();

        return new UserBackupService(
            $userId,
            $databaseService,
            $backupProcessor,
            $fileStorageService,
            $accountIds,
            $activeIds,
            ignoredTables: [],
        );
    }

    private function seedData(): void
    {
        $connection = app('db')->connection('testing');

        $connection->table('users')->insert([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $connection->table('transactions')->insert([
            ['id' => 1, 'account_id' => 1001, 'amount' => 10.50],
            ['id' => 2, 'account_id' => 1001, 'amount' => 15.25],
            ['id' => 3, 'account_id' => 2002, 'amount' => 99.99],
        ]);

        $connection->table('positions')->insert([
            ['id' => 1, 'user_id' => 1, 'active_id' => 501, 'symbol' => 'AAPL'],
            ['id' => 2, 'user_id' => 2, 'active_id' => 777, 'symbol' => 'MSFT'],
        ]);
    }

    private function cleanupBackups(): void
    {
        $baseDir = base_path('resources/backup_actives');

        if (!is_dir($baseDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($baseDir);
    }
}
