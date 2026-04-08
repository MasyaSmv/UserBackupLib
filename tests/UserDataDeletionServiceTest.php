<?php

declare(strict_types=1);

namespace Tests;

use App\Contracts\DatabaseServiceInterface;
use App\Services\UserDataDeletionService;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Mockery;

/**
 * @allure.suite("UserBackup")
 * @allure.epic("UserBackupLib")
 * @allure.owner("backend")
 * @allure.lead("backend")
 * @allure.layer("unit")
 * @allure.tag("cleanup", "backup")
 */
class UserDataDeletionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_deletes_user_related_data(): void
    {
        $databaseService = Mockery::mock(DatabaseServiceInterface::class);
        $databaseService->shouldReceive('getConnections')->once()->andReturn(['testing']);

        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('users')->andReturnTrue();
        $schema->shouldReceive('hasTable')->with('transactions')->andReturnTrue();
        $schema->shouldReceive('hasTable')->with('positions')->andReturnTrue();

        $usersQuery = Mockery::mock(QueryBuilder::class);
        $usersQuery->shouldReceive('whereIn')->once()->with('id', [1])->andReturnSelf();
        $usersQuery->shouldReceive('delete')->once();

        $transactionsQuery = Mockery::mock(QueryBuilder::class);
        $transactionsQuery->shouldReceive('whereIn')->once()->with('account_id', [1001])->andReturnSelf();
        $transactionsQuery->shouldReceive('delete')->once();

        $positionsQuery = Mockery::mock(QueryBuilder::class);
        $positionsQuery->shouldReceive('whereIn')->once()->with('user_id', [1])->andReturnSelf();
        $positionsQuery->shouldReceive('delete')->once();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('select')
            ->once()
            ->with("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->andReturn([
                (object) ['name' => 'users'],
                (object) ['name' => 'transactions'],
                (object) ['name' => 'positions'],
            ]);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('select')
            ->times(3)
            ->withArgs(function (string $query) {
                return str_starts_with($query, 'PRAGMA table_info(');
            })
            ->andReturnUsing(static function (string $query): array {
                if (str_contains($query, 'users')) {
                    return [(object) ['name' => 'id'], (object) ['name' => 'name']];
                }

                if (str_contains($query, 'transactions')) {
                    return [(object) ['name' => 'id'], (object) ['name' => 'account_id']];
                }

                return [(object) ['name' => 'id'], (object) ['name' => 'user_id']];
            });
        $connection->shouldReceive('table')->with('users')->andReturn($usersQuery);
        $connection->shouldReceive('table')->with('transactions')->andReturn($transactionsQuery);
        $connection->shouldReceive('table')->with('positions')->andReturn($positionsQuery);
        $connection->shouldReceive('getPdo')->andReturn(new class {
            public function quote(string $table): string
            {
                return $table;
            }
        });

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $deletionService = new UserDataDeletionService($databaseService);

        $deletionService->deleteUserData(
            userId: 1,
            accountIds: [1001],
            activeIds: [501],
            ignoredTables: [],
        );

        $this->assertTrue(true);
    }

    public function test_it_skips_ignored_tables_and_unknown_fields(): void
    {
        $databaseService = Mockery::mock(DatabaseServiceInterface::class);
        $databaseService->shouldReceive('getConnections')->once()->andReturn(['testing']);

        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('users')->never();
        $schema->shouldReceive('hasTable')->with('logs')->andReturnTrue();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('select')
            ->once()
            ->with("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->andReturn([
                (object) ['name' => 'users'],
                (object) ['name' => 'logs'],
            ]);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function (string $query) {
                return str_starts_with($query, 'PRAGMA table_info(');
            })
            ->andReturn([(object) ['name' => 'id'], (object) ['name' => 'message']]);
        $connection->shouldReceive('getPdo')->andReturn(new class {
            public function quote(string $table): string
            {
                return $table;
            }
        });
        $connection->shouldNotReceive('table');

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $deletionService = new UserDataDeletionService($databaseService);

        $deletionService->deleteUserData(
            userId: 1,
            accountIds: [1001],
            activeIds: [501],
            ignoredTables: ['users'],
        );

        $this->assertTrue(true);
    }

    public function test_it_skips_tables_when_params_are_empty_for_detected_field(): void
    {
        $databaseService = Mockery::mock(DatabaseServiceInterface::class);
        $databaseService->shouldReceive('getConnections')->once()->andReturn(['testing']);

        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('assets')->andReturnTrue();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('select')
            ->once()
            ->with("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->andReturn([
                (object) ['name' => 'assets'],
            ]);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function (string $query) {
                return str_starts_with($query, 'PRAGMA table_info(');
            })
            ->andReturn([(object) ['name' => 'id'], (object) ['name' => 'active_id']]);
        $connection->shouldReceive('getPdo')->andReturn(new class {
            public function quote(string $table): string
            {
                return $table;
            }
        });
        $connection->shouldNotReceive('table');

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $deletionService = new UserDataDeletionService($databaseService);

        $deletionService->deleteUserData(
            userId: 1,
            accountIds: [1001],
            activeIds: [],
            ignoredTables: [],
        );

        $this->assertTrue(true);
    }

    public function test_it_skips_missing_tables(): void
    {
        $databaseService = Mockery::mock(DatabaseServiceInterface::class);
        $databaseService->shouldReceive('getConnections')->once()->andReturn(['testing']);

        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('users')->andReturnFalse();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('select')
            ->once()
            ->with("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->andReturn([(object) ['name' => 'users']]);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldNotReceive('table');

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $service = new UserDataDeletionService($databaseService);
        $service->deleteUserData(1, [1001], [501], []);

        $this->assertTrue(true);
    }

    public function test_it_deletes_user_subaccounts_by_account_ids_in_current_implementation(): void
    {
        $databaseService = Mockery::mock(DatabaseServiceInterface::class);
        $databaseService->shouldReceive('getConnections')->once()->andReturn(['testing']);

        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('user_subaccounts')->andReturnTrue();

        $query = Mockery::mock(QueryBuilder::class);
        $query->shouldReceive('whereIn')->once()->with('id', [1001, 1002])->andReturnSelf();
        $query->shouldReceive('delete')->once();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('select')
            ->once()
            ->with("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")
            ->andReturn([(object) ['name' => 'user_subaccounts']]);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function (string $query) {
                return str_starts_with($query, 'PRAGMA table_info(');
            })
            ->andReturn([(object) ['name' => 'id']]);
        $connection->shouldReceive('getPdo')->andReturn(new class {
            public function quote(string $table): string
            {
                return $table;
            }
        });
        $connection->shouldReceive('table')->with('user_subaccounts')->andReturn($query);

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $service = new UserDataDeletionService($databaseService);
        $service->deleteUserData(1, [1001, 1002], [], []);

        $this->assertTrue(true);
    }

    public function test_it_reads_mysql_table_list_for_deletion_flow(): void
    {
        $databaseService = Mockery::mock(DatabaseServiceInterface::class);
        $databaseService->shouldReceive('getConnections')->once()->andReturn(['mysql']);

        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('users')->andReturnTrue();

        $query = Mockery::mock(QueryBuilder::class);
        $query->shouldReceive('whereIn')->once()->with('id', [1])->andReturnSelf();
        $query->shouldReceive('delete')->once();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('select')
            ->once()
            ->with('SHOW TABLES')
            ->andReturn([['Tables_in_app' => 'users']]);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('getDatabaseName')->andReturn('app');
        $connection->shouldReceive('select')
            ->once()
            ->with(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                ['app', 'users'],
            )
            ->andReturn([(object) ['COLUMN_NAME' => 'id']]);
        $connection->shouldReceive('table')->with('users')->andReturn($query);

        DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

        $service = new UserDataDeletionService($databaseService);
        $service->deleteUserData(1, [], [], []);

        $this->assertTrue(true);
    }

    public function test_build_params_returns_empty_array_for_unknown_id_mapping(): void
    {
        $service = new UserDataDeletionService(Mockery::mock(DatabaseServiceInterface::class));

        $method = new \ReflectionMethod($service, 'buildParams');
        $method->setAccessible(true);

        $result = $method->invoke($service, 'some_table', 'id', 1, [1001], [501]);

        $this->assertSame([], $result);
    }
}
