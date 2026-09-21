<?php

declare(strict_types=1);

namespace Tests\Plan;

use App\Plan\CompiledUserDataPlan;
use App\Plan\Exceptions\PlanIncompleteException;
use App\Plan\Exceptions\RowLimitExceededException;
use App\Plan\Exceptions\SchemaMismatchException;
use App\Plan\Execution\GuardedPlanRunner;
use App\Plan\Execution\PlanExecutor;
use App\Plan\Execution\RowLimitGuard;
use App\Plan\Execution\RowLimits;
use App\Plan\Preflight\PlanPreflight;
use App\Plan\ScopeKey;
use App\Plan\ScopeValues;
use App\Plan\Selector\InScope;
use App\Plan\TableRef;
use App\Plan\UserDataRule;
use App\ValueObjects\FilterValues;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Проверяется главное свойство безопасного запуска: при любом расхождении плана со
 * схемой и при превышении предохранителя данные остаются нетронутыми.
 */
class GuardedPlanRunnerTest extends TestCase
{
    private const CONNECTION = 'testing';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('active_goals', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
        });

        Schema::create('api_tokens', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
        });

        DB::table('active_goals')->insert([
            ['id' => 1, 'user_id' => 42],
            ['id' => 2, 'user_id' => 42],
            ['id' => 3, 'user_id' => 77],
        ]);

        DB::table('api_tokens')->insert(['id' => 1, 'user_id' => 42]);
    }

    private function runner(): GuardedPlanRunner
    {
        $resolver = DB::getFacadeRoot();

        return new GuardedPlanRunner(
            new PlanPreflight($resolver),
            PlanExecutor::default($resolver),
            new RowLimitGuard(),
        );
    }

    private function scope(): ScopeValues
    {
        return new ScopeValues([ScopeKey::USER => new FilterValues([42])]);
    }

    /**
     * Полный план: покрывает обе таблицы, созданные в setUp.
     */
    private function fullPlan(): CompiledUserDataPlan
    {
        return new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goals'),
                new InScope('user_id', ScopeKey::user()),
            ),
            UserDataRule::keep(new TableRef(self::CONNECTION, 'api_tokens')),
        ]);
    }

    public function test_full_plan_runs_and_deletes_only_user_rows(): void
    {
        $report = $this->runner()->run(
            $this->fullPlan(),
            $this->scope(),
            [self::CONNECTION],
            RowLimits::unlimited(),
        );

        $this->assertSame(2, $report->totalRows());
        $this->assertSame(1, DB::table('active_goals')->count());
        $this->assertSame(1, DB::table('api_tokens')->count());
    }

    public function test_uncovered_table_stops_the_run_before_any_delete(): void
    {
        // api_tokens намеренно не классифицирована — план неполон.
        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goals'),
                new InScope('user_id', ScopeKey::user()),
            ),
        ]);

        try {
            $this->runner()->run($plan, $this->scope(), [self::CONNECTION], RowLimits::unlimited());
            $this->fail('Ожидалось исключение о неполном плане.');
        } catch (PlanIncompleteException $e) {
            $this->assertContains('testing.api_tokens', $e->tableKeys());
        }

        $this->assertSame(3, DB::table('active_goals')->count(), 'Ни одна строка не должна пострадать');
    }

    public function test_missing_column_stops_the_run_before_any_delete(): void
    {
        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goals'),
                new InScope('owner_id', ScopeKey::user()),
            ),
            UserDataRule::keep(new TableRef(self::CONNECTION, 'api_tokens')),
        ]);

        try {
            $this->runner()->run($plan, $this->scope(), [self::CONNECTION], RowLimits::unlimited());
            $this->fail('Ожидалось исключение о расхождении со схемой.');
        } catch (SchemaMismatchException $e) {
            $this->assertSame('user_data_plan.schema_mismatch', $e->errorCode());
            $this->assertStringContainsString('owner_id', $e->problems()[0]);
        }

        $this->assertSame(3, DB::table('active_goals')->count());
    }

    public function test_rule_for_table_absent_in_this_schema_is_only_a_warning(): void
    {
        // Набор таблиц различается между окружениями: на стенде может не быть таблиц
        // авторизации, которые есть на проде. Удалять там нечего, останавливаться не из-за чего.
        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goals'),
                new InScope('user_id', ScopeKey::user()),
            ),
            UserDataRule::keep(new TableRef(self::CONNECTION, 'api_tokens')),
            UserDataRule::keep(new TableRef(self::CONNECTION, 'oauth_access_tokens')),
        ]);

        $report = $this->runner()->run(
            $plan,
            $this->scope(),
            [self::CONNECTION],
            RowLimits::unlimited(),
        );

        $this->assertSame(2, $report->totalRows());
        $this->assertSame(1, DB::table('active_goals')->count());
    }

    public function test_preflight_reports_tables_absent_in_schema(): void
    {
        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goals'),
                new InScope('user_id', ScopeKey::user()),
            ),
            UserDataRule::keep(new TableRef(self::CONNECTION, 'api_tokens')),
            UserDataRule::keep(new TableRef(self::CONNECTION, 'table_that_was_dropped')),
        ]);

        $report = (new PlanPreflight(DB::getFacadeRoot()))->check($plan, [self::CONNECTION]);

        $this->assertTrue($report->hasWarnings());
        $this->assertSame(
            ['testing.table_that_was_dropped'],
            $report->tablesMissingInSchema(),
        );
    }

    public function test_row_limit_stops_the_run_before_any_delete(): void
    {
        try {
            $this->runner()->run(
                $this->fullPlan(),
                $this->scope(),
                [self::CONNECTION],
                new RowLimits(1),
            );

            $this->fail('Ожидалось исключение о превышении лимита.');
        } catch (RowLimitExceededException $e) {
            $this->assertSame('user_data_plan.row_limit_exceeded', $e->errorCode());
            $this->assertSame('testing.active_goals', $e->scope());
            $this->assertSame(2, $e->context()['rows']);
        }

        $this->assertSame(3, DB::table('active_goals')->count(), 'Предохранитель обязан сработать до удаления');
    }

    public function test_total_limit_is_checked_across_tables(): void
    {
        $this->expectException(RowLimitExceededException::class);

        $this->runner()->run(
            $this->fullPlan(),
            $this->scope(),
            [self::CONNECTION],
            new RowLimits(null, 1),
        );
    }

    public function test_limit_equal_to_scope_allows_the_run(): void
    {
        $report = $this->runner()->run(
            $this->fullPlan(),
            $this->scope(),
            [self::CONNECTION],
            new RowLimits(2, 2),
        );

        $this->assertSame(2, $report->totalRows());
        $this->assertSame(1, DB::table('active_goals')->count());
    }

    public function test_preview_checks_schema_and_changes_nothing(): void
    {
        $report = $this->runner()->preview($this->fullPlan(), $this->scope(), [self::CONNECTION]);

        $this->assertTrue($report->isDryRun());
        $this->assertSame(2, $report->totalRows());
        $this->assertSame(3, DB::table('active_goals')->count());
    }
}
