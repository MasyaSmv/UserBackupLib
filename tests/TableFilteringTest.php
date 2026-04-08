<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Concerns\TableFiltering;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;

class TableFilteringTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_table_columns_for_sqlite(): void
    {
        $pdo = new class {
            public function quote(string $table): string
            {
                return $table;
            }
        };

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('sqlite');
        $connection->shouldReceive('getPdo')->andReturn($pdo);
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(static function (string $query): bool {
                return str_starts_with($query, 'PRAGMA table_info(');
            })
            ->andReturn([(object) ['name' => 'id'], (object) ['name' => 'name']]);

        DB::shouldReceive('connection')->with('testing')->andReturn($connection);

        $helper = new class {
            use TableFiltering;

            public function columns(string $table, string $connectionName): array
            {
                return $this->getTableColumns($table, $connectionName);
            }

            public function field(string $table, array $columns): ?string
            {
                return $this->determineFilterField($table, $columns);
            }

            public function params(string $field, array $columns, array $params): array
            {
                return $this->prepareParams($field, $columns, $params);
            }
        };

        $this->assertSame(['id', 'name'], $helper->columns('users', 'testing'));
    }

    public function test_get_table_columns_for_mysql(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')->andReturn('app_db');
        $connection->shouldReceive('select')
            ->once()
            ->with(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                ['app_db', 'users'],
            )
            ->andReturn([(object) ['COLUMN_NAME' => 'id'], (object) ['COLUMN_NAME' => 'user_id']]);

        DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

        $helper = new class {
            use TableFiltering;

            public function columns(string $table, string $connectionName): array
            {
                return $this->getTableColumns($table, $connectionName);
            }

            public function field(string $table, array $columns): ?string
            {
                return $this->determineFilterField($table, $columns);
            }

            public function params(string $field, array $columns, array $params): array
            {
                return $this->prepareParams($field, $columns, $params);
            }
        };

        $this->assertSame(['id', 'user_id'], $helper->columns('users', 'mysql'));
    }

    public function test_determine_filter_field_uses_expected_priority(): void
    {
        $helper = new class {
            use TableFiltering;

            public function field(string $table, array $columns): ?string
            {
                return $this->determineFilterField($table, $columns);
            }
        };

        $this->assertSame('id', $helper->field('users', ['id']));
        $this->assertSame('id', $helper->field('user_subaccounts', ['id', 'account_id']));
        $this->assertSame('user_id', $helper->field('positions', ['user_id', 'account_id']));
        $this->assertSame('account_id', $helper->field('transactions', ['account_id']));
        $this->assertSame('from_account_id', $helper->field('transfers', ['from_account_id', 'to_account_id']));
        $this->assertSame('to_account_id', $helper->field('refunds', ['to_account_id']));
        $this->assertSame('active_id', $helper->field('assets', ['active_id']));
        $this->assertNull($helper->field('logs', ['id', 'message']));
    }

    public function test_prepare_params_returns_same_params(): void
    {
        $helper = new class {
            use TableFiltering;

            public function params(string $field, array $columns, array $params): array
            {
                return $this->prepareParams($field, $columns, $params);
            }
        };

        $this->assertSame([1, 2, 3], $helper->params('user_id', ['user_id'], [1, 2, 3]));
    }
}
