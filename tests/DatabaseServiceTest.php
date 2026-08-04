<?php

declare(strict_types=1);

namespace Tests;

use App\Services\DatabaseService;
use App\ValueObjects\FilterSubquery;
use App\ValueObjects\TableQueryParameters;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        // Таблицы 'users' в схеме нет → ConnectionSchema::hasTable вернёт false.
        $service = new DatabaseService(['testing']);

        $this->assertSame([], iterator_to_array($service->streamUserData('users', ['id' => [1]], 'testing')));
    }

    public function test_stream_user_data_returns_empty_when_filter_field_is_unknown(): void
    {
        Schema::connection('testing')->create('logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('message');
        });

        // Нет user_id/account_id/active_id → фильтр-поле не определяется → выгрузки нет.
        $service = new DatabaseService(['testing']);

        $this->assertSame([], iterator_to_array($service->streamUserData('logs', ['id' => [1]], 'testing')));
    }

    public function test_stream_user_data_returns_empty_when_filter_values_are_empty(): void
    {
        Schema::connection('testing')->create('transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('account_id');
        });

        // Фильтр-поле account_id есть, но значений не передано → пустой фильтр → выгрузки нет.
        $service = new DatabaseService(['testing']);

        $this->assertSame([], iterator_to_array($service->streamUserData('transactions', [], 'testing')));
    }

    /**
     * Keyset-пагинация (lazyById) должна выгрузить ВСЕ строки пользователя без пропусков
     * и дублей, когда их больше одного чанка, и отфильтровать чужие. Проверяем на реальном
     * SQLite, т.к. корректность границ чанка невозможно проверить моками.
     */
    public function test_stream_keyset_yields_all_matching_rows_without_gaps_or_duplicates(): void
    {
        $connection = DB::connection('testing');
        Schema::connection('testing')->create('transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('account_id');
        });

        // 25 строк нашего аккаунта вперемешку с чужими — id заведомо не подряд.
        $expectedIds = [];
        for ($i = 0; $i < 25; $i++) {
            $connection->table('transactions')->insert(['account_id' => 1001]);
            $expectedIds[] = (int) $connection->getPdo()->lastInsertId();
            $connection->table('transactions')->insert(['account_id' => 2002]);
        }

        $service = new DatabaseService(['testing']);
        $rows = iterator_to_array(
            $service->streamUserData('transactions', ['account_id' => [1001]], 'testing', 10)
        );

        $ids = array_map('intval', array_column($rows, 'id'));

        self::assertCount(25, $rows, 'Должны выгрузиться все 25 строк аккаунта');
        self::assertSame($expectedIds, $ids, 'Порядок по PK, без пропусков и дублей');
        self::assertSame([1001], array_map('intval', array_values(array_unique(array_column($rows, 'account_id')))));
    }

    /**
     * Фильтр-подзапрос по active_id должен вернуть ровно те же строки, что и литеральный
     * whereIn с явным списком id активов пользователя.
     */
    public function test_stream_subquery_filter_is_equivalent_to_literal_filter(): void
    {
        $connection = DB::connection('testing');

        Schema::connection('testing')->create('actives', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
        });
        Schema::connection('testing')->create('positions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('active_id');
        });

        // Активы: 1,2 у пользователя 7; актив 3 у чужого пользователя 9.
        $connection->table('actives')->insert([
            ['id' => 1, 'user_id' => 7],
            ['id' => 2, 'user_id' => 7],
            ['id' => 3, 'user_id' => 9],
        ]);
        $connection->table('positions')->insert([
            ['active_id' => 1],
            ['active_id' => 2],
            ['active_id' => 3],
            ['active_id' => 1],
        ]);

        $service = new DatabaseService(['testing']);

        $viaLiteral = iterator_to_array(
            $service->streamUserData('positions', ['active_id' => [1, 2]], 'testing', 100)
        );

        $params = new TableQueryParameters(
            [],
            ['active_id' => new FilterSubquery('actives', 'id', 'user_id', 7)]
        );
        $viaSubquery = iterator_to_array(
            $service->streamUserData('positions', $params, 'testing', 100)
        );

        self::assertSame($viaLiteral, $viaSubquery);
        self::assertSame([1, 2, 1], array_map('intval', array_column($viaSubquery, 'active_id')));
    }

    /**
     * Для таблицы с составным PK keyset невозможен — должен сработать OFFSET-fallback (lazy),
     * при этом все отфильтрованные строки обязаны выгрузиться.
     */
    public function test_stream_falls_back_to_offset_for_composite_primary_key(): void
    {
        $connection = DB::connection('testing');
        Schema::connection('testing')->create('active_tag', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_id');
            $table->unsignedBigInteger('tag_id');
            $table->primary(['active_id', 'tag_id']);
        });

        $connection->table('active_tag')->insert([
            ['active_id' => 5, 'tag_id' => 1],
            ['active_id' => 5, 'tag_id' => 2],
            ['active_id' => 6, 'tag_id' => 1],
        ]);

        $service = new DatabaseService(['testing']);
        $rows = iterator_to_array(
            $service->streamUserData('active_tag', ['active_id' => [5]], 'testing', 1)
        );

        self::assertCount(2, $rows);
        self::assertSame([1, 2], array_map('intval', array_column($rows, 'tag_id')));
    }

    public function test_stream_returns_empty_when_subquery_table_absent_and_no_literals(): void
    {
        Schema::connection('testing')->create('positions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('active_id');
        });
        DB::connection('testing')->table('positions')->insert(['active_id' => 1]);

        $service = new DatabaseService(['testing']);

        // Спецификация ссылается на несуществующую таблицу actives, литералов нет →
        // fallback на пустой LiteralFilter → выгрузка не выполняется.
        $params = new TableQueryParameters(
            [],
            ['active_id' => new FilterSubquery('actives', 'id', 'user_id', 7)]
        );

        self::assertSame([], iterator_to_array($service->streamUserData('positions', $params, 'testing')));
    }
}
