<?php

declare(strict_types=1);

namespace Tests;

use App\Services\DatabaseService;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mockery;

class DatabaseServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_connections_returns_constructor_value(): void
    {
        $service = new DatabaseService(['mysql', 'replica']);

        $this->assertSame(['mysql', 'replica'], $service->getConnections());
    }

    public function test_fetch_user_data_from_all_databases_merges_results(): void
    {
        $service = Mockery::mock(DatabaseService::class, [['mysql', 'replica']])->makePartial();
        $service->shouldReceive('fetchUserData')->once()->with('users', ['id' => [1]], 'mysql')->andReturn([['id' => 1]]);
        $service->shouldReceive('fetchUserData')->once()->with('users', ['id' => [1]], 'replica')->andReturn([['id' => 2]]);

        $this->assertSame([['id' => 1], ['id' => 2]], $service->fetchUserDataFromAllDatabases('users', ['id' => [1]]));
    }

    public function test_fetch_user_data_from_all_databases_returns_empty_array_when_nothing_found(): void
    {
        $service = Mockery::mock(DatabaseService::class, [['mysql']])->makePartial();
        $service->shouldReceive('fetchUserData')->once()->with('users', ['id' => [1]], 'mysql')->andReturn([]);

        $this->assertSame([], $service->fetchUserDataFromAllDatabases('users', ['id' => [1]]));
    }

    public function test_fetch_user_data_materializes_stream(): void
    {
        $service = Mockery::mock(DatabaseService::class, [['mysql']])->makePartial();
        $generator = (static function () {
            yield ['id' => 1];
            yield ['id' => 2];
        })();

        $service->shouldReceive('streamUserData')->once()->with('users', ['id' => [1]], 'mysql')->andReturn($generator);

        $this->assertSame([['id' => 1], ['id' => 2]], $service->fetchUserData('users', ['id' => [1]], 'mysql'));
    }

    public function test_stream_user_data_returns_empty_when_table_is_missing(): void
    {
        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('users')->andReturnFalse();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $service = new DatabaseService(['testing']);

        $this->assertSame([], iterator_to_array($service->streamUserData('users', ['id' => [1]], 'testing')));
    }

    public function test_stream_user_data_returns_empty_when_filter_field_is_unknown(): void
    {
        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('logs')->andReturnTrue();

        $pdo = new class {
            public function quote(string $table): string
            {
                return $table;
            }
        };

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('select')
            ->once()
            ->with('PRAGMA table_info(logs)')
            ->andReturn([(object) ['name' => 'id'], (object) ['name' => 'message']]);

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $service = new DatabaseService(['testing']);

        $this->assertSame([], iterator_to_array($service->streamUserData('logs', ['id' => [1]], 'testing')));
    }

    public function test_stream_user_data_returns_empty_when_filter_values_are_empty(): void
    {
        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('transactions')->andReturnTrue();

        $pdo = new class {
            public function quote(string $table): string
            {
                return $table;
            }
        };

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('select')
            ->once()
            ->with('PRAGMA table_info(transactions)')
            ->andReturn([(object) ['name' => 'id'], (object) ['name' => 'account_id']]);

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $service = new DatabaseService(['testing']);

        $this->assertSame([], iterator_to_array($service->streamUserData('transactions', [], 'testing')));
    }

    public function test_stream_user_data_yields_rows_across_pages(): void
    {
        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('transactions')->andReturnTrue();

        $query = Mockery::mock(QueryBuilder::class);
        $query->shouldReceive('whereIn')->once()->with('account_id', [1001])->andReturnSelf();
        $query->shouldReceive('orderBy')->once()->with('account_id')->andReturnSelf();
        $query->shouldReceive('forPage')->once()->with(1, 2)->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn(new Collection([
            (object) ['id' => 1, 'account_id' => 1001],
            (object) ['id' => 2, 'account_id' => 1001],
        ]));

        $querySecond = Mockery::mock(QueryBuilder::class);
        $querySecond->shouldReceive('whereIn')->once()->with('account_id', [1001])->andReturnSelf();
        $querySecond->shouldReceive('orderBy')->once()->with('account_id')->andReturnSelf();
        $querySecond->shouldReceive('forPage')->once()->with(2, 2)->andReturnSelf();
        $querySecond->shouldReceive('get')->once()->andReturn(new Collection([]));

        $pdo = new class {
            public function quote(string $table): string
            {
                return $table;
            }
        };

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('select')
            ->once()
            ->with('PRAGMA table_info(transactions)')
            ->andReturn([(object) ['name' => 'id'], (object) ['name' => 'account_id']]);
        $connection->shouldReceive('table')->twice()->with('transactions')->andReturn($query, $querySecond);

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $service = new DatabaseService(['testing']);
        $rows = iterator_to_array($service->streamUserData('transactions', ['account_id' => [1001]], 'testing', 2));

        $this->assertSame([
            ['id' => 1, 'account_id' => 1001],
            ['id' => 2, 'account_id' => 1001],
        ], $rows);
    }
}
