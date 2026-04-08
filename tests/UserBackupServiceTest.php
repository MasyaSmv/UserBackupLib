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
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user-backup-lib-tests';

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
        $path = $service->saveBackupToFile($this->makeBackupPath(), false);

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
        $path = $service->saveBackupToFile($this->makeBackupPath(), true);

        $this->assertFileExists($path);
        $this->assertSame('enc', pathinfo($path, PATHINFO_EXTENSION));

        $data = FileStorageService::decryptFile($path);

        $this->assertArrayHasKey('users', $data);
        $this->assertSame('Alice', $data['users'][0]['name']);
    }

    /**
     * @allure.title("Потоковое чтение незашифрованного бэкапа")
     * @allure.description("Проверяем, что backup-файл можно читать последовательно без полной материализации JSON.")
     * @allure.severity(critical)
     * @allure.story("Чтение бэкапа")
     */
    public function test_it_streams_plain_backup_data(): void
    {
        $service = $this->makeBackupService(
            userId: 1,
            accountIds: [1001],
            activeIds: [501],
        );

        $service->fetchAllUserData();
        $path = $service->saveBackupToFile($this->makeBackupPath(), false);

        $entries = iterator_to_array((new FileStorageService())->streamBackupData($path), false);

        $this->assertCount(4, $entries);
        $this->assertSame('users', $entries[0]['table']);
        $this->assertSame('Alice', $entries[0]['row']['name']);
        $this->assertSame('transactions', $entries[1]['table']);
        $this->assertSame('positions', $entries[3]['table']);
    }

    /**
     * @allure.title("Потоковое чтение зашифрованного бэкапа")
     * @allure.description("Проверяем, что зашифрованный backup читается построчно и отдает строки последовательно.")
     * @allure.severity(critical)
     * @allure.story("Чтение бэкапа")
     */
    public function test_it_streams_encrypted_backup_data(): void
    {
        $service = $this->makeBackupService(
            userId: 1,
            accountIds: [1001],
            activeIds: [501],
        );

        $service->fetchAllUserData();
        $path = $service->saveBackupToFile($this->makeBackupPath(), true);

        $entries = iterator_to_array((new FileStorageService())->streamBackupData($path), false);

        $this->assertCount(4, $entries);
        $this->assertSame('users', $entries[0]['table']);
        $this->assertSame('Alice', $entries[0]['row']['name']);
        $this->assertSame('positions', $entries[3]['table']);
        $this->assertSame('AAPL', $entries[3]['row']['symbol']);
    }

    /**
     * @allure.title("Потоковое чтение учитывает пустые таблицы")
     * @allure.description("Пустой массив таблицы не должен ломать parser и не должен отдавать фиктивные строки.")
     * @allure.severity(normal)
     * @allure.story("Чтение бэкапа")
     */
    public function test_it_handles_empty_tables_in_stream_reader(): void
    {
        $storage = new FileStorageService();
        $path = base_path('resources/backup_actives/test-empty/empty.json');

        $storage->saveToFile($path, [
            'users' => [[['id' => 1, 'name' => 'Alice']]],
            'transactions' => [[]],
        ], false);

        $entries = iterator_to_array($storage->streamBackupData($path), false);

        $this->assertCount(1, $entries);
        $this->assertSame('users', $entries[0]['table']);
        $this->assertSame('Alice', $entries[0]['row']['name']);
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
        $baseDir = $this->backupDir;

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

    private function makeBackupPath(): string
    {
        return $this->backupDir . DIRECTORY_SEPARATOR . 'backup.json';
    }
}
