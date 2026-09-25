<?php

declare(strict_types=1);

namespace Tests\Plan;

use UserDataBackup\Plan\CompiledUserDataPlan;
use UserDataBackup\Plan\Exceptions\PlanIncompleteException;
use UserDataBackup\Plan\ScopeKey;
use UserDataBackup\Plan\Selector\ExistsInParent;
use UserDataBackup\Plan\Selector\InScope;
use UserDataBackup\Plan\TableRef;
use UserDataBackup\Plan\UserDataRule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CompiledUserDataPlanTest extends TestCase
{
    public function test_same_table_name_in_different_connections_is_not_a_duplicate(): void
    {
        $plan = new CompiledUserDataPlan([
            UserDataRule::keep(new TableRef('mysql', 'failed_jobs')),
            UserDataRule::keep(new TableRef('catalog', 'failed_jobs')),
        ]);

        $this->assertTrue($plan->has(new TableRef('mysql', 'failed_jobs')));
        $this->assertTrue($plan->has(new TableRef('catalog', 'failed_jobs')));
        $this->assertCount(2, $plan->rules());
    }

    public function test_duplicate_rule_for_same_table_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CompiledUserDataPlan([
            UserDataRule::keep(new TableRef('mysql', 'jobs')),
            UserDataRule::keep(new TableRef('mysql', 'jobs')),
        ]);
    }

    public function test_uncovered_table_blocks_the_operation(): void
    {
        $plan = new CompiledUserDataPlan([
            UserDataRule::keep(new TableRef('mysql', 'jobs')),
        ]);

        try {
            $plan->assertCovers([
                new TableRef('mysql', 'jobs'),
                new TableRef('mysql', 'brand_new_table'),
                new TableRef('catalog', 'another_new_table'),
            ]);

            $this->fail('Ожидалось исключение о неполном плане.');
        } catch (PlanIncompleteException $e) {
            $this->assertSame('user_data_plan.incomplete', $e->errorCode());
            $this->assertSame(
                ['mysql.brand_new_table', 'catalog.another_new_table'],
                $e->tableKeys(),
            );
            $this->assertSame(2, $e->context()['tables_count']);
        }
    }

    public function test_fully_covered_schema_passes(): void
    {
        $plan = new CompiledUserDataPlan([
            UserDataRule::keep(new TableRef('mysql', 'jobs')),
            UserDataRule::keep(new TableRef('catalog', 'migrations')),
        ]);

        $plan->assertCovers([
            new TableRef('mysql', 'jobs'),
            new TableRef('catalog', 'migrations'),
        ]);

        $this->assertTrue(true);
    }

    public function test_keep_rules_are_excluded_from_readable(): void
    {
        $goals = new TableRef('mysql', 'active_goals');

        $plan = new CompiledUserDataPlan([
            UserDataRule::keep(new TableRef('mysql', 'jobs')),
            UserDataRule::backupAndDelete($goals, new InScope('user_id', ScopeKey::user())),
        ]);

        $readable = $plan->readable();

        $this->assertCount(1, $readable);
        $this->assertSame('mysql.active_goals', $readable[0]->tableRef()->key());
    }

    public function test_version_changes_when_action_changes(): void
    {
        $table = new TableRef('mysql', 'aton_operations');

        $keepPlan = new CompiledUserDataPlan([UserDataRule::keep($table)]);
        $deletePlan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete($table, new InScope('user_id', ScopeKey::user())),
        ]);

        $this->assertNotSame($keepPlan->version(), $deletePlan->version());
    }

    public function test_version_is_stable_regardless_of_rule_order(): void
    {
        $jobs = UserDataRule::keep(new TableRef('mysql', 'jobs'));
        $goals = UserDataRule::backupAndDelete(
            new TableRef('mysql', 'active_goals'),
            new InScope('user_id', ScopeKey::user()),
        );

        $first = new CompiledUserDataPlan([$jobs, $goals]);
        $second = new CompiledUserDataPlan([$goals, $jobs]);

        $this->assertSame($first->version(), $second->version());
    }

    public function test_deletion_order_is_exposed_through_the_plan(): void
    {
        $goals = new TableRef('mysql', 'active_goals');
        $payments = new TableRef('mysql', 'active_goal_payments');
        $userOwned = new InScope('user_id', ScopeKey::user());

        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete($goals, $userOwned),
            UserDataRule::backupAndDelete(
                $payments,
                new ExistsInParent('goal_id', $goals, 'id', $userOwned),
            ),
        ]);

        $keys = array_map(
            static fn (UserDataRule $rule): string => $rule->tableRef()->key(),
            $plan->inDeletionOrder(),
        );

        $this->assertSame(
            ['mysql.active_goal_payments', 'mysql.active_goals'],
            $keys,
        );
    }

    public function test_without_tables_drops_rules_and_keeps_source_version(): void
    {
        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(new TableRef('mysql', 'users'), new InScope('id', ScopeKey::user())),
            UserDataRule::backupAndDelete(
                new TableRef('mysql', 'oauth_access_tokens'),
                new InScope('user_id', ScopeKey::user())
            ),
        ]);

        $trimmed = $plan->withoutTables(['mysql.oauth_access_tokens']);

        $this->assertTrue($trimmed->has(new TableRef('mysql', 'users')));
        $this->assertFalse($trimmed->has(new TableRef('mysql', 'oauth_access_tokens')));
        $this->assertSame(
            $plan->version(),
            $trimmed->version(),
            'Version is a fingerprint of the description, not of one environment schema'
        );
    }

    public function test_without_tables_returns_same_instance_when_nothing_to_drop(): void
    {
        $plan = new CompiledUserDataPlan([
            UserDataRule::keep(new TableRef('mysql', 'jobs')),
        ]);

        $this->assertSame($plan, $plan->withoutTables([]));
    }
}
