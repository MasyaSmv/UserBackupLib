<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\BackupProcessorInterface;
use App\Contracts\DatabaseServiceInterface;
use App\Contracts\FileStorageServiceInterface;
use App\Services\BackupProcessor;
use App\Services\DatabaseService;
use App\Services\FileStorageService;
use App\Services\UserBackupService;
use App\ValueObjects\UserBackupCreateOptions;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Mockery;

/**
 * @allure.suite("UserBackup")
 * @allure.epic("UserBackupLib")
 * @allure.owner("backend")
 * @allure.lead("backend")
 * @allure.layer("unit")
 * @allure.tag("backup", "stream", "encryption")
 */
class UserBackupServiceTest extends TestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user-backup-lib-tests';
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupBackups();
        parent::tearDown();
    }

    public function test_backup_streams_and_saves_without_encryption(): void
    {
        $storage = new FileStorageService();
        $path = $storage->saveToFile($this->makeBackupPath(), $this->makeBackupPayload(), false);

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertArrayHasKey('users', $payload);
        $this->assertCount(1, $payload['users']);
        $this->assertSame('Alice', $payload['users'][0]['name']);

        $this->assertArrayHasKey('transactions', $payload);
        $this->assertCount(2, $payload['transactions']);

        $this->assertArrayHasKey('positions', $payload);
        $this->assertCount(1, $payload['positions']);
        $this->assertSame('AAPL', $payload['positions'][0]['symbol']);
    }

    public function test_backup_streams_and_encrypts(): void
    {
        $storage = new FileStorageService();
        $path = $storage->saveToFile($this->makeBackupPath(), $this->makeBackupPayload(), true);

        $this->assertFileExists($path);
        $this->assertSame('enc', pathinfo($path, PATHINFO_EXTENSION));

        $data = FileStorageService::decryptFile($path);

        $this->assertArrayHasKey('users', $data);
        $this->assertSame('Alice', $data['users'][0]['name']);
    }

    public function test_it_streams_plain_backup_data(): void
    {
        $storage = new FileStorageService();
        $path = $storage->saveToFile($this->makeBackupPath(), $this->makeBackupPayload(), false);

        $entries = iterator_to_array((new FileStorageService())->streamBackupData($path), false);

        $this->assertCount(4, $entries);
        $this->assertSame('users', $entries[0]['table']);
        $this->assertSame('Alice', $entries[0]['row']['name']);
        $this->assertSame('transactions', $entries[1]['table']);
        $this->assertSame('positions', $entries[3]['table']);
    }

    public function test_it_streams_encrypted_backup_data(): void
    {
        $storage = new FileStorageService();
        $path = $storage->saveToFile($this->makeBackupPath(), $this->makeBackupPayload(), true);

        $entries = iterator_to_array((new FileStorageService())->streamBackupData($path), false);

        $this->assertCount(4, $entries);
        $this->assertSame('users', $entries[0]['table']);
        $this->assertSame('Alice', $entries[0]['row']['name']);
        $this->assertSame('positions', $entries[3]['table']);
        $this->assertSame('AAPL', $entries[3]['row']['symbol']);
    }

    public function test_it_handles_empty_tables_in_stream_reader(): void
    {
        $storage = new FileStorageService();
        $path = $this->backupDir . DIRECTORY_SEPARATOR . 'empty.json';

        $storage->saveToFile($path, [
            'users' => [[['id' => 1, 'name' => 'Alice']]],
            'transactions' => [[]],
        ], false);

        $entries = iterator_to_array($storage->streamBackupData($path), false);

        $this->assertCount(1, $entries);
        $this->assertSame('users', $entries[0]['table']);
        $this->assertSame('Alice', $entries[0]['row']['name']);
    }

    public function test_fetch_all_user_data_collects_streams_from_connections(): void
    {
        $databaseService = Mockery::mock(DatabaseServiceInterface::class);
        $backupProcessor = Mockery::mock(BackupProcessorInterface::class);
        $fileStorageService = Mockery::mock(FileStorageServiceInterface::class);

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('hasTable')->with('users')->andReturnTrue();
        $schema->shouldReceive('hasTable')->with('positions')->andReturnTrue();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('select')
            ->once()
            ->with("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->andReturn([(object) ['name' => 'users'], (object) ['name' => 'positions']]);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $usersStream = $this->makeGenerator([['id' => 42, 'name' => 'Alice']]);
        $positionsStream = $this->makeGenerator([['id' => 1, 'user_id' => 42, 'symbol' => 'AAPL']]);

        $databaseService->shouldReceive('getConnections')->once()->andReturn(['testing']);
        $databaseService->shouldReceive('streamUserData')
            ->once()
            ->with('users', ['id' => [42]], 'testing')
            ->andReturn($usersStream);
        $databaseService->shouldReceive('streamUserData')
            ->once()
            ->with('positions', ['user_id' => [42], 'account_id' => [1001], 'active_id' => [501]], 'testing')
            ->andReturn($positionsStream);

        $backupProcessor->shouldReceive('clearUserData')->once();
        $backupProcessor->shouldReceive('appendUserData')->once()->with('users', $usersStream);
        $backupProcessor->shouldReceive('appendUserData')->once()->with('positions', $positionsStream);
        $backupProcessor->shouldReceive('getUserData')->once()->andReturn([
            'users' => [$usersStream],
            'positions' => [$positionsStream],
        ]);

        $service = new UserBackupService(
            42,
            $databaseService,
            $backupProcessor,
            $fileStorageService,
            [1001],
            [501],
            [],
        );

        $result = $service->fetchAllUserData();

        $this->assertArrayHasKey('users', $result);
        $this->assertArrayHasKey('positions', $result);
    }

    public function test_fetch_all_user_data_skips_ignored_and_missing_tables(): void
    {
        $databaseService = Mockery::mock(DatabaseServiceInterface::class);
        $backupProcessor = Mockery::mock(BackupProcessorInterface::class);
        $fileStorageService = Mockery::mock(FileStorageServiceInterface::class);

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('hasTable')->with('users')->never();
        $schema->shouldReceive('hasTable')->with('positions')->andReturnFalse();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('select')
            ->once()
            ->with("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->andReturn([(object) ['name' => 'users'], (object) ['name' => 'positions']]);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $databaseService->shouldReceive('getConnections')->once()->andReturn(['testing']);
        $databaseService->shouldNotReceive('streamUserData');

        $backupProcessor->shouldReceive('clearUserData')->once();
        $backupProcessor->shouldReceive('getUserData')->once()->andReturn([]);
        $backupProcessor->shouldNotReceive('appendUserData');

        $service = new UserBackupService(
            42,
            $databaseService,
            $backupProcessor,
            $fileStorageService,
            [1001],
            [501],
            ['users'],
        );

        $this->assertSame([], $service->fetchAllUserData());
    }

    public function test_save_backup_to_file_delegates_to_storage_service(): void
    {
        $databaseService = Mockery::mock(DatabaseServiceInterface::class);
        $backupProcessor = Mockery::mock(BackupProcessorInterface::class);
        $fileStorageService = Mockery::mock(FileStorageServiceInterface::class);

        $expectedData = [
            'users' => [$this->makeGenerator([['id' => 1]])],
        ];

        $backupProcessor->shouldReceive('clearUserData')->once();
        $backupProcessor->shouldReceive('getUserData')->once()->andReturn($expectedData);
        $backupProcessor->shouldReceive('appendUserData')->once();

        $databaseService->shouldReceive('getConnections')->once()->andReturn(['testing']);

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('hasTable')->with('users')->andReturnTrue();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('select')
            ->once()
            ->with("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->andReturn([(object) ['name' => 'users']]);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $stream = $this->makeGenerator([['id' => 1]]);
        $databaseService->shouldReceive('streamUserData')
            ->once()
            ->with('users', ['id' => [1]], 'testing')
            ->andReturn($stream);

        $fileStorageService->shouldReceive('saveToFile')
            ->once()
            ->with('/tmp/output.json', $expectedData, false)
            ->andReturn('/tmp/output.json');

        $service = new UserBackupService(
            1,
            $databaseService,
            $backupProcessor,
            $fileStorageService,
        );

        $service->fetchAllUserData();

        $this->assertSame('/tmp/output.json', $service->saveBackupToFile('/tmp/output.json', false));
    }

    public function test_create_builds_default_service_graph(): void
    {
        $service = UserBackupService::create(
            userId: 42,
            accountIds: [1001],
            activeIds: [501],
            ignoredTables: ['logs'],
            connections: ['mysql'],
        );

        $this->assertInstanceOf(UserBackupService::class, $service);

        $reflection = new \ReflectionClass($service);

        $databaseProperty = $reflection->getProperty('databaseService');
        $databaseProperty->setAccessible(true);
        $this->assertInstanceOf(DatabaseService::class, $databaseProperty->getValue($service));

        $backupProperty = $reflection->getProperty('backupProcessor');
        $backupProperty->setAccessible(true);
        $this->assertInstanceOf(BackupProcessor::class, $backupProperty->getValue($service));

        $storageProperty = $reflection->getProperty('fileStorageService');
        $storageProperty->setAccessible(true);
        $this->assertInstanceOf(FileStorageService::class, $storageProperty->getValue($service));
    }

    public function test_create_from_options_builds_default_service_graph(): void
    {
        $options = UserBackupCreateOptions::fromLegacy(
            userId: 42,
            accountIds: [1001],
            activeIds: [501],
            ignoredTables: ['logs'],
            connections: ['mysql'],
        );

        $service = UserBackupService::createFromOptions($options);

        $this->assertInstanceOf(UserBackupService::class, $service);

        $reflection = new \ReflectionClass($service);

        $databaseProperty = $reflection->getProperty('databaseService');
        $databaseProperty->setAccessible(true);
        $this->assertInstanceOf(DatabaseService::class, $databaseProperty->getValue($service));
    }

    public function test_fetch_all_user_data_reads_mysql_table_listing(): void
    {
        $databaseService = Mockery::mock(DatabaseServiceInterface::class);
        $backupProcessor = Mockery::mock(BackupProcessorInterface::class);
        $fileStorageService = Mockery::mock(FileStorageServiceInterface::class);

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('hasTable')->with('users')->andReturnTrue();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('select')->once()->with('SHOW TABLES')->andReturn([['Tables_in_app' => 'users']]);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

        DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

        $stream = $this->makeGenerator([['id' => 7]]);

        $databaseService->shouldReceive('getConnections')->once()->andReturn(['mysql']);
        $databaseService->shouldReceive('streamUserData')->once()->with('users', ['id' => [7]], 'mysql')->andReturn($stream);

        $backupProcessor->shouldReceive('clearUserData')->once();
        $backupProcessor->shouldReceive('appendUserData')->once()->with('users', $stream);
        $backupProcessor->shouldReceive('getUserData')->once()->andReturn(['users' => [$stream]]);

        $service = new UserBackupService(
            7,
            $databaseService,
            $backupProcessor,
            $fileStorageService,
        );

        $result = $service->fetchAllUserData();

        $this->assertArrayHasKey('users', $result);
    }

    private function makeBackupPayload(): array
    {
        $users = $this->makeGenerator([['id' => 1, 'name' => 'Alice']]);
        $transactions = $this->makeGenerator([
            ['id' => 1, 'account_id' => 1001, 'amount' => 10.50],
            ['id' => 2, 'account_id' => 1001, 'amount' => 15.25],
        ]);
        $positions = $this->makeGenerator([['id' => 1, 'user_id' => 1, 'active_id' => 501, 'symbol' => 'AAPL']]);

        return [
            'users' => [$users],
            'transactions' => [$transactions],
            'positions' => [$positions],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function makeGenerator(array $rows): Generator
    {
        foreach ($rows as $row) {
            yield $row;
        }
    }

    private function cleanupBackups(): void
    {
        if (!is_dir($this->backupDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->backupDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->backupDir);
    }

    private function makeBackupPath(): string
    {
        return $this->backupDir . DIRECTORY_SEPARATOR . uniqid('backup_', true) . '.json';
    }
}
