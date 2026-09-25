<?php

declare(strict_types=1);

namespace Tests\Plan;

use UserDataBackup\Plan\Compiler\SelectorCompilerChain;
use UserDataBackup\Plan\Exceptions\CrossConnectionParentException;
use UserDataBackup\Plan\ScopeKey;
use UserDataBackup\Plan\ScopeValues;
use UserDataBackup\Plan\Selector\AnyOf;
use UserDataBackup\Plan\Selector\Equals;
use UserDataBackup\Plan\Selector\ExistsInParent;
use UserDataBackup\Plan\Selector\InScope;
use UserDataBackup\Plan\Selector\MorphBranch;
use UserDataBackup\Plan\Selector\MorphReference;
use UserDataBackup\Plan\TableRef;
use UserDataBackup\ValueObjects\FilterValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * Компиляция селекторов проверяется на настоящей базе: условие должно отбирать ровно
 * те строки, которые принадлежат пользователю, и не задевать соседние.
 */
class SelectorCompilerChainTest extends TestCase
{
    private SelectorCompilerChain $chain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chain = SelectorCompilerChain::default();

        Schema::create('active_goals', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
        });

        Schema::create('active_goal_payments', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('goal_id');
        });

        Schema::create('active_trades', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('active_id')->nullable();
            $table->integer('from_account_id')->nullable();
            $table->integer('to_account_id')->nullable();
        });

        Schema::create('active_group_items', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('item_type');
            $table->integer('item_id');
        });

        Schema::create('custom_stocks', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('user_id');
        });

        Schema::create('email_codes', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
        });
    }

    private function scope(array $values): ScopeValues
    {
        $prepared = [];

        foreach ($values as $key => $items) {
            $prepared[$key] = new FilterValues($items);
        }

        return new ScopeValues($prepared);
    }

    /**
     * @return array<int, int>
     */
    private function selectIds(string $table, $selector, ScopeValues $scope): array
    {
        $query = DB::connection()->table($table);

        $this->chain->apply($query, $selector, $scope, 'testing');

        return array_map('intval', $query->orderBy('id')->pluck('id')->all());
    }

    public function test_in_scope_selects_only_rows_of_the_user(): void
    {
        DB::table('active_goals')->insert([
            ['id' => 1, 'user_id' => 42],
            ['id' => 2, 'user_id' => 77],
            ['id' => 3, 'user_id' => 42],
        ]);

        $ids = $this->selectIds(
            'active_goals',
            new InScope('user_id', ScopeKey::user()),
            $this->scope([ScopeKey::USER => [42]]),
        );

        $this->assertSame([1, 3], $ids);
    }

    public function test_contact_scope_selects_rows_bound_by_email_instead_of_user_id(): void
    {
        // email_codes и password_resets связаны с человеком почтой: числового user_id у
        // них нет вовсе, поэтому набор скоупа здесь строковый.
        DB::table('email_codes')->insert([
            ['id' => 1, 'email' => 'owner@example.com'],
            ['id' => 2, 'email' => 'someone.else@example.com'],
            ['id' => 3, 'email' => 'owner@example.com'],
        ]);

        $ids = $this->selectIds(
            'email_codes',
            new InScope('email', ScopeKey::emails()),
            $this->scope([ScopeKey::EMAILS => ['owner@example.com']]),
        );

        $this->assertSame([1, 3], $ids);
    }

    public function test_empty_contact_scope_leaves_foreign_rows_untouched(): void
    {
        // Пользователь без почты не должен уносить чужие коды: пустой набор — пустая
        // выборка, иначе чистка контактов станет чисткой всей таблицы.
        DB::table('email_codes')->insert([
            ['id' => 1, 'email' => 'someone.else@example.com'],
        ]);

        $ids = $this->selectIds(
            'email_codes',
            new InScope('email', ScopeKey::emails()),
            $this->scope([ScopeKey::EMAILS => []]),
        );

        $this->assertSame([], $ids);
    }

    public function test_empty_scope_selects_nothing_instead_of_everything(): void
    {
        DB::table('active_goals')->insert([
            ['id' => 1, 'user_id' => 42],
            ['id' => 2, 'user_id' => 77],
        ]);

        $ids = $this->selectIds(
            'active_goals',
            new InScope('user_id', ScopeKey::user()),
            $this->scope([]),
        );

        $this->assertSame(
            [],
            $ids,
            'Пустой набор скоупа обязан давать пустую выборку, иначе удаление снесёт чужие строки',
        );
    }

    public function test_child_rows_are_found_through_the_parent(): void
    {
        DB::table('active_goals')->insert([
            ['id' => 1, 'user_id' => 42],
            ['id' => 2, 'user_id' => 77],
        ]);

        DB::table('active_goal_payments')->insert([
            ['id' => 10, 'goal_id' => 1],
            ['id' => 11, 'goal_id' => 2],
            ['id' => 12, 'goal_id' => 1],
            ['id' => 13, 'goal_id' => 999],
        ]);

        $ids = $this->selectIds(
            'active_goal_payments',
            new ExistsInParent(
                'goal_id',
                new TableRef('testing', 'active_goals'),
                'id',
                new InScope('user_id', ScopeKey::user()),
            ),
            $this->scope([ScopeKey::USER => [42]]),
        );

        $this->assertSame([10, 12], $ids);
    }

    public function test_any_of_catches_rows_filled_through_different_fields(): void
    {
        // Ровно тот случай, на котором ломалась прежняя эвристика: она брала одно поле.
        DB::table('active_trades')->insert([
            ['id' => 1, 'active_id' => 5, 'from_account_id' => null, 'to_account_id' => null],
            ['id' => 2, 'active_id' => null, 'from_account_id' => 100, 'to_account_id' => null],
            ['id' => 3, 'active_id' => null, 'from_account_id' => null, 'to_account_id' => 100],
            ['id' => 4, 'active_id' => 999, 'from_account_id' => 999, 'to_account_id' => 999],
        ]);

        $ids = $this->selectIds(
            'active_trades',
            AnyOf::of(
                new InScope('active_id', ScopeKey::actives()),
                new InScope('from_account_id', ScopeKey::subaccounts()),
                new InScope('to_account_id', ScopeKey::subaccounts()),
            ),
            $this->scope([
                ScopeKey::ACTIVES => [5],
                ScopeKey::SUBACCOUNTS => [100],
            ]),
        );

        $this->assertSame([1, 2, 3], $ids);
    }

    public function test_any_of_returns_each_row_once(): void
    {
        DB::table('active_trades')->insert([
            ['id' => 1, 'active_id' => 5, 'from_account_id' => 100, 'to_account_id' => 100],
        ]);

        $ids = $this->selectIds(
            'active_trades',
            AnyOf::of(
                new InScope('active_id', ScopeKey::actives()),
                new InScope('from_account_id', ScopeKey::subaccounts()),
                new InScope('to_account_id', ScopeKey::subaccounts()),
            ),
            $this->scope([
                ScopeKey::ACTIVES => [5],
                ScopeKey::SUBACCOUNTS => [100],
            ]),
        );

        $this->assertSame([1], $ids, 'Строка, подходящая по трём полям, не должна дублироваться');
    }

    public function test_morph_pair_does_not_capture_same_id_of_another_type(): void
    {
        DB::table('active_group_items')->insert([
            ['id' => 1, 'item_type' => 'Active', 'item_id' => 5],
            ['id' => 2, 'item_type' => 'UserSubaccount', 'item_id' => 5],
            ['id' => 3, 'item_type' => 'UserSubaccount', 'item_id' => 100],
            ['id' => 4, 'item_type' => 'Foreign', 'item_id' => 5],
        ]);

        $ids = $this->selectIds(
            'active_group_items',
            new MorphReference('item_type', 'item_id', [
                MorphBranch::of(new InScope('item_id', ScopeKey::actives()), 'Active'),
                MorphBranch::of(new InScope('item_id', ScopeKey::subaccounts()), 'UserSubaccount'),
            ]),
            $this->scope([
                ScopeKey::ACTIVES => [5],
                ScopeKey::SUBACCOUNTS => [100],
            ]),
        );

        $this->assertSame(
            [1, 3],
            $ids,
            'Идентификатор 5 в типе UserSubaccount и неизвестный тип не принадлежат пользователю',
        );
    }

    public function test_legacy_type_aliases_are_matched_by_the_same_branch(): void
    {
        // В схеме годами копились алиасы прежних версий кода: у активов это `active`
        // сегодня и `active.3202`, `active.3106` в старых строках. Ветка, знающая только
        // текущий алиас, оставила бы старые записи в базе навсегда.
        DB::table('active_group_items')->insert([
            ['id' => 1, 'item_type' => 'active', 'item_id' => 5],
            ['id' => 2, 'item_type' => 'active.3202', 'item_id' => 5],
            ['id' => 3, 'item_type' => 'active.3106', 'item_id' => 5],
            ['id' => 4, 'item_type' => 'active.group', 'item_id' => 5],
        ]);

        $ids = $this->selectIds(
            'active_group_items',
            new MorphReference('item_type', 'item_id', [
                MorphBranch::of(
                    new InScope('item_id', ScopeKey::actives()),
                    'active',
                    'active.3202',
                    'active.3106',
                ),
            ]),
            $this->scope([ScopeKey::ACTIVES => [5]]),
        );

        $this->assertSame(
            [1, 2, 3],
            $ids,
            'Все алиасы одной сущности обязаны попадать в одну ветку',
        );
    }

    public function test_same_type_in_two_branches_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MorphReference('item_type', 'item_id', [
            MorphBranch::of(new InScope('item_id', ScopeKey::actives()), 'active'),
            MorphBranch::of(new InScope('item_id', ScopeKey::subaccounts()), 'active'),
        ]);
    }

    public function test_catalog_string_user_id_matches_through_tenant_scope(): void
    {
        DB::table('custom_stocks')->insert([
            ['id' => 1, 'user_id' => 'production-2275'],
            ['id' => 2, 'user_id' => 'production-777'],
            ['id' => 3, 'user_id' => 'testing-2275'],
        ]);

        $ids = $this->selectIds(
            'custom_stocks',
            new InScope('user_id', ScopeKey::userTenantKey()),
            $this->scope([ScopeKey::USER_TENANT_KEY => ['production-2275']]),
        );

        $this->assertSame([1], $ids);
    }

    public function test_integer_user_id_does_not_match_prefixed_catalog_value(): void
    {
        // Корень дефекта WS-2929: сравнение строкового user_id с целым не находит ничего.
        DB::table('custom_stocks')->insert([
            ['id' => 1, 'user_id' => 'production-2275'],
        ]);

        $ids = $this->selectIds(
            'custom_stocks',
            new InScope('user_id', ScopeKey::user()),
            $this->scope([ScopeKey::USER => [2275]]),
        );

        $this->assertSame([], $ids);
    }

    public function test_equals_narrows_selection(): void
    {
        DB::table('active_group_items')->insert([
            ['id' => 1, 'item_type' => 'Active', 'item_id' => 5],
            ['id' => 2, 'item_type' => 'UserSubaccount', 'item_id' => 5],
        ]);

        $ids = $this->selectIds(
            'active_group_items',
            new Equals('item_type', 'Active'),
            $this->scope([]),
        );

        $this->assertSame([1], $ids);
    }

    public function test_parent_in_another_connection_is_rejected(): void
    {
        $this->expectException(CrossConnectionParentException::class);

        $this->selectIds(
            'active_goal_payments',
            new ExistsInParent(
                'goal_id',
                new TableRef('catalog', 'active_goals'),
                'id',
                new InScope('user_id', ScopeKey::user()),
            ),
            $this->scope([ScopeKey::USER => [42]]),
        );
    }
}
