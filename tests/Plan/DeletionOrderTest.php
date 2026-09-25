<?php

declare(strict_types=1);

namespace Tests\Plan;

use UserDataBackup\Plan\Exceptions\PlanCycleException;
use UserDataBackup\Plan\DeletionOrder;
use UserDataBackup\Plan\ScopeKey;
use UserDataBackup\Plan\Selector\ExistsInParent;
use UserDataBackup\Plan\Selector\InScope;
use UserDataBackup\Plan\TableAction;
use UserDataBackup\Plan\TableRef;
use UserDataBackup\Plan\UserDataRule;
use PHPUnit\Framework\TestCase;

class DeletionOrderTest extends TestCase
{
    public function test_child_rules_are_ordered_before_their_parents(): void
    {
        $goals = new TableRef('mysql', 'active_goals');
        $payments = new TableRef('mysql', 'active_goal_payments');

        $goalsRule = UserDataRule::backupAndDelete(
            $goals,
            new InScope('user_id', ScopeKey::user()),
        );

        $paymentsRule = UserDataRule::backupAndDelete(
            $payments,
            new ExistsInParent('goal_id', $goals, 'id', new InScope('user_id', ScopeKey::user())),
        );

        // Родитель передан первым — порядок должен определяться зависимостями, а не входом.
        $ordered = (new DeletionOrder())->sort([
            $goals->key() => $goalsRule,
            $payments->key() => $paymentsRule,
        ]);

        $keys = array_map(
            static fn (UserDataRule $rule): string => $rule->tableRef()->key(),
            $ordered,
        );

        $this->assertSame(
            ['mysql.active_goal_payments', 'mysql.active_goals'],
            $keys,
            'Платежи цели обязаны удаляться до самой цели, иначе подзапрос не найдёт родителя',
        );
    }

    public function test_grandchild_is_ordered_before_child_and_parent(): void
    {
        $actives = new TableRef('mysql', 'actives');
        $trades = new TableRef('mysql', 'active_trades');
        $commissions = new TableRef('mysql', 'active_trade_commissions');

        $userOwned = new InScope('user_id', ScopeKey::user());

        $rules = [
            $actives->key() => UserDataRule::backupAndDelete($actives, $userOwned),
            $trades->key() => UserDataRule::backupAndDelete(
                $trades,
                new ExistsInParent('active_id', $actives, 'id', $userOwned),
            ),
            $commissions->key() => UserDataRule::backupAndDelete(
                $commissions,
                new ExistsInParent(
                    'active_trade_id',
                    $trades,
                    'id',
                    new ExistsInParent('active_id', $actives, 'id', $userOwned),
                ),
            ),
        ];

        $keys = array_map(
            static fn (UserDataRule $rule): string => $rule->tableRef()->key(),
            (new DeletionOrder())->sort($rules),
        );

        $this->assertSame(
            [
                'mysql.active_trade_commissions',
                'mysql.active_trades',
                'mysql.actives',
            ],
            $keys,
        );
    }

    public function test_scope_root_is_processed_after_every_other_rule(): void
    {
        $users = new TableRef('mysql', 'users');
        $actives = new TableRef('mysql', 'actives');
        $trades = new TableRef('mysql', 'active_trades');
        $userOwned = new InScope('user_id', ScopeKey::user());

        // Корень передан первым и не связан с остальными через селектор: без метки он
        // оказался бы в начале порядка.
        $rules = [
            $users->key() => UserDataRule::backupAndDelete($users, new InScope('id', ScopeKey::user()))->asScopeRoot(),
            $actives->key() => UserDataRule::backupAndDelete($actives, $userOwned),
            $trades->key() => UserDataRule::backupAndDelete(
                $trades,
                new ExistsInParent('active_id', $actives, 'id', $userOwned),
            ),
        ];

        $keys = array_map(
            static fn (UserDataRule $rule): string => $rule->tableRef()->key(),
            (new DeletionOrder())->sort($rules),
        );

        $this->assertSame(
            ['mysql.active_trades', 'mysql.actives', 'mysql.users'],
            $keys,
            'Корень скоупа обязан удаляться последним: сбой до него оставляет аккаунт находимым',
        );
    }

    public function test_scope_root_with_parent_in_plan_is_rejected(): void
    {
        $users = new TableRef('mysql', 'users');
        $accounts = new TableRef('mysql', 'accounts');
        $userOwned = new InScope('user_id', ScopeKey::user());

        $this->expectException(PlanCycleException::class);

        (new DeletionOrder())->sort([
            $accounts->key() => UserDataRule::backupAndDelete($accounts, $userOwned),
            $users->key() => UserDataRule::backupAndDelete(
                $users,
                new ExistsInParent('account_id', $accounts, 'id', $userOwned),
            )->asScopeRoot(),
        ]);
    }

    public function test_scope_root_marker_does_not_mutate_the_source_rule(): void
    {
        $rule = UserDataRule::backupAndDelete(new TableRef('mysql', 'users'), new InScope('id', ScopeKey::user()));

        $this->assertTrue($rule->asScopeRoot()->isScopeRoot());
        $this->assertFalse($rule->isScopeRoot(), 'Правило неизменяемо: метка ставится на копию');
    }

    public function test_keep_rules_do_not_constrain_order(): void
    {
        $jobs = new TableRef('mysql', 'jobs');
        $tokens = new TableRef('mysql', 'api_tokens');

        $ordered = (new DeletionOrder())->sort([
            $jobs->key() => UserDataRule::keep($jobs),
            $tokens->key() => UserDataRule::keep($tokens),
        ]);

        $this->assertCount(2, $ordered);
    }

    public function test_cycle_between_rules_is_rejected(): void
    {
        $first = new TableRef('mysql', 'first_table');
        $second = new TableRef('mysql', 'second_table');
        $userOwned = new InScope('user_id', ScopeKey::user());

        $rules = [
            $first->key() => UserDataRule::backupAndDelete(
                $first,
                new ExistsInParent('second_id', $second, 'id', $userOwned),
            ),
            $second->key() => UserDataRule::backupAndDelete(
                $second,
                new ExistsInParent('first_id', $first, 'id', $userOwned),
            ),
        ];

        $this->expectException(PlanCycleException::class);

        (new DeletionOrder())->sort($rules);
    }

    public function test_cycle_exception_carries_error_code_and_tables(): void
    {
        $first = new TableRef('mysql', 'first_table');
        $second = new TableRef('mysql', 'second_table');
        $userOwned = new InScope('user_id', ScopeKey::user());

        try {
            (new DeletionOrder())->sort([
                $first->key() => UserDataRule::backupAndDelete(
                    $first,
                    new ExistsInParent('second_id', $second, 'id', $userOwned),
                ),
                $second->key() => UserDataRule::backupAndDelete(
                    $second,
                    new ExistsInParent('first_id', $first, 'id', $userOwned),
                ),
            ]);

            $this->fail('Ожидалось исключение о циклической зависимости.');
        } catch (PlanCycleException $e) {
            $this->assertSame('user_data_plan.cycle', $e->errorCode());
            $this->assertSame(
                ['mysql.first_table', 'mysql.second_table'],
                $e->tableKeys(),
            );
            $this->assertSame('user_data_plan.cycle', $e->context()['error_code']);
        }
    }

    public function test_parent_outside_plan_does_not_block_ordering(): void
    {
        $external = new TableRef('catalog', 'ticker_hubs');
        $child = new TableRef('mysql', 'actives');

        $rules = [
            $child->key() => UserDataRule::backupAndDelete(
                $child,
                new ExistsInParent(
                    'ticker_hub_id',
                    $external,
                    'id',
                    new InScope('user_id', ScopeKey::user()),
                ),
            ),
        ];

        $ordered = (new DeletionOrder())->sort($rules);

        $this->assertCount(1, $ordered);
        $this->assertSame(TableAction::BACKUP_AND_DELETE, $ordered[0]->action()->value());
    }
}
