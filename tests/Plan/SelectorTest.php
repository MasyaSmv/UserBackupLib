<?php

declare(strict_types=1);

namespace Tests\Plan;

use App\Plan\ScopeKey;
use App\Plan\Selector\AnyOf;
use App\Plan\Selector\Equals;
use App\Plan\Selector\ExistsInParent;
use App\Plan\Selector\InScope;
use App\Plan\Selector\MorphBranch;
use App\Plan\Selector\MorphReference;
use App\Plan\TableAction;
use App\Plan\TableRef;
use App\Plan\UserDataRule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SelectorTest extends TestCase
{
    public function test_any_of_collects_every_column_of_a_multi_field_table(): void
    {
        // active_trades: три поля связи одновременно. Прежний движок брал одно по приоритету.
        $selector = AnyOf::of(
            new InScope('active_id', ScopeKey::actives()),
            new InScope('from_account_id', ScopeKey::subaccounts()),
            new InScope('to_account_id', ScopeKey::subaccounts()),
        );

        $this->assertSame(
            ['active_id', 'from_account_id', 'to_account_id'],
            $selector->columns(),
        );
    }

    public function test_any_of_deduplicates_scope_keys(): void
    {
        $selector = AnyOf::of(
            new InScope('from_account_id', ScopeKey::subaccounts()),
            new InScope('to_account_id', ScopeKey::subaccounts()),
        );

        $keys = array_map(
            static fn (ScopeKey $key): string => $key->value(),
            $selector->scopeKeys(),
        );

        $this->assertSame([ScopeKey::SUBACCOUNTS], $keys);
    }

    public function test_any_of_requires_at_least_two_conditions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AnyOf::of(new InScope('user_id', ScopeKey::user()));
    }

    public function test_catalog_user_id_is_expressed_through_tenant_scope_key(): void
    {
        // custom_stocks хранит user_id строкой `{tenant}-{id}`: селектор об этом не знает,
        // знание о формате остаётся у резолвера, который наполняет набор.
        $selector = new InScope('user_id', ScopeKey::userTenantKey());

        $this->assertSame(ScopeKey::USER_TENANT_KEY, $selector->scopeKey()->value());
        $this->assertSame(['user_id'], $selector->columns());
    }

    public function test_morph_reference_requires_branches_to_check_the_id_column(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MorphReference('item_type', 'item_id', [
            MorphBranch::of(new InScope('wrong_column', ScopeKey::actives()), 'active'),
        ]);
    }

    public function test_morph_reference_exposes_both_columns_of_the_pair(): void
    {
        $selector = new MorphReference('item_type', 'item_id', [
            MorphBranch::of(new InScope('item_id', ScopeKey::actives()), 'active', 'active.3202'),
            MorphBranch::of(new InScope('item_id', ScopeKey::subaccounts()), 'account.currency'),
        ]);

        $this->assertSame(['item_type', 'item_id'], $selector->columns());
        $this->assertCount(2, $selector->branches());
    }

    public function test_morph_reference_rejects_empty_branches(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MorphReference('item_type', 'item_id', []);
    }

    public function test_exists_in_parent_inherits_scope_keys_from_parent_selector(): void
    {
        $selector = new ExistsInParent(
            'goal_id',
            new TableRef('mysql', 'active_goals'),
            'id',
            new InScope('user_id', ScopeKey::user()),
        );

        $keys = array_map(
            static fn (ScopeKey $key): string => $key->value(),
            $selector->scopeKeys(),
        );

        $this->assertSame([ScopeKey::USER], $keys);
        $this->assertSame(['goal_id'], $selector->columns());
    }

    public function test_equals_needs_no_scope(): void
    {
        $selector = new Equals('item_type', 'Active');

        $this->assertSame([], $selector->scopeKeys());
        $this->assertSame('Active', $selector->value());
    }

    public function test_keep_rule_must_not_carry_a_selector(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UserDataRule(
            new TableRef('mysql', 'jobs'),
            TableAction::keep(),
            new InScope('user_id', ScopeKey::user()),
        );
    }

    public function test_deleting_rule_requires_a_selector(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UserDataRule(new TableRef('mysql', 'actives'), TableAction::backupAndDelete());
    }

    public function test_detach_rule_requires_columns_to_clear(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UserDataRule::detach(
            new TableRef('mysql', 'relations'),
            new InScope('user_id1', ScopeKey::user()),
            [],
        );
    }

    public function test_required_columns_include_primary_key_for_deleting_rules(): void
    {
        $rule = UserDataRule::backupAndDelete(
            new TableRef('mysql', 'active_goal_payments'),
            new ExistsInParent(
                'goal_id',
                new TableRef('mysql', 'active_goals'),
                'id',
                new InScope('user_id', ScopeKey::user()),
            ),
        );

        $this->assertSame(['goal_id', 'id'], $rule->requiredColumns());
    }

    public function test_rule_derives_parents_from_its_selector(): void
    {
        $goals = new TableRef('mysql', 'active_goals');

        $rule = UserDataRule::backupAndDelete(
            new TableRef('mysql', 'active_goal_payments'),
            new ExistsInParent('goal_id', $goals, 'id', new InScope('user_id', ScopeKey::user())),
        );

        $this->assertSame(['mysql.active_goals'], array_keys($rule->parents()));
    }

    public function test_rule_derives_parents_through_morph_branches(): void
    {
        $actives = new TableRef('mysql', 'actives');
        $trades = new TableRef('mysql', 'active_trades');
        $userOwned = new InScope('user_id', ScopeKey::user());

        $rule = UserDataRule::backupAndDelete(
            new TableRef('mysql', 'active_actions'),
            new MorphReference('main_item_type', 'main_item_id', [
                MorphBranch::of(new ExistsInParent('main_item_id', $actives, 'id', $userOwned), 'active'),
                MorphBranch::of(new ExistsInParent('main_item_id', $trades, 'id', $userOwned), 'active.trade'),
            ]),
        );

        $this->assertSame(
            ['mysql.actives', 'mysql.active_trades'],
            array_keys($rule->parents()),
        );
    }
}
