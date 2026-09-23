<?php

declare(strict_types=1);

namespace Tests\Plan;

use App\Plan\CompiledUserDataPlan;
use App\Plan\Exceptions\PlanIncompleteException;
use App\Plan\Exceptions\RowLimitExceededException;
use App\Plan\Exceptions\SchemaMismatchException;
use App\Plan\Compiler\SelectorCompilerChain;
use App\Plan\Exceptions\UncheckedConnectionException;
use App\Plan\Exceptions\UnhandledActionException;
use App\Plan\Exceptions\UnsupportedSelectorException;
use App\Plan\Execution\GuardedPlanRunner;
use App\Plan\Execution\PlanCompilationCheck;
use App\Plan\Execution\PlanExecutor;
use App\Plan\Execution\RowLimitGuard;
use App\Plan\Execution\RowLimits;
use App\Plan\Preflight\PlanPreflight;
use App\Plan\ScopeKey;
use App\Plan\ScopeValues;
use App\Plan\Selector\InScope;
use App\Plan\Selector\Selector;
use App\Plan\TableAction;
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
            new PlanCompilationCheck($resolver, SelectorCompilerChain::default()),
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
        $this->assertSame(
            ['testing.oauth_access_tokens'],
            $report->skippedTables(),
            'Урезание плана под схему обязано быть видно в отчёте',
        );
        $this->assertSame(['testing.oauth_access_tokens'], $report->toArray()['skipped_tables']);
    }

    public function test_rule_on_unchecked_connection_stops_the_run_before_any_delete(): void
    {
        // Подключение catalog не передано: правила на нём раньше молча выпадали из плана.
        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goals'),
                new InScope('user_id', ScopeKey::user()),
            ),
            UserDataRule::keep(new TableRef(self::CONNECTION, 'api_tokens')),
            UserDataRule::backupAndDelete(
                new TableRef('catalog', 'custom_stocks'),
                new InScope('user_id', ScopeKey::user()),
            ),
        ]);

        try {
            $this->runner()->run($plan, $this->scope(), [self::CONNECTION], RowLimits::unlimited());
            $this->fail('Ожидалось исключение о непроверенном подключении.');
        } catch (UncheckedConnectionException $e) {
            $this->assertSame('user_data_plan.unchecked_connection', $e->errorCode());
            $this->assertSame(['catalog'], $e->connections());
            $this->assertSame(['catalog.custom_stocks'], $e->context()['tables']);
        }

        $this->assertSame(3, DB::table('active_goals')->count(), 'Ни одна строка не должна пострадать');
    }

    public function test_empty_connection_list_is_not_a_successful_empty_run(): void
    {
        $this->expectException(UncheckedConnectionException::class);

        $this->runner()->run($this->fullPlan(), $this->scope(), [], RowLimits::unlimited());
    }

    public function test_uncompilable_rule_stops_the_run_without_limits(): void
    {
        // Лимитов нет, сухого прогона нет — ошибку описания обязана поймать компиляция.
        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goals'),
                new InScope('user_id', ScopeKey::user()),
            ),
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'api_tokens'),
                $this->unsupportedSelector(),
            ),
        ]);

        try {
            $this->runner()->run($plan, $this->scope(), [self::CONNECTION], RowLimits::unlimited());
            $this->fail('Ожидалось исключение о неподдерживаемом селекторе.');
        } catch (UnsupportedSelectorException $e) {
            $this->assertSame('user_data_plan.unsupported_selector', $e->errorCode());
        }

        $this->assertSame(3, DB::table('active_goals')->count());
        $this->assertSame(1, DB::table('api_tokens')->count());
    }

    public function test_mutating_action_without_handler_stops_the_run_before_any_delete(): void
    {
        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goals'),
                new InScope('user_id', ScopeKey::user()),
            ),
            new UserDataRule(
                new TableRef(self::CONNECTION, 'api_tokens'),
                TableAction::anonymize(),
                new InScope('user_id', ScopeKey::user()),
            ),
        ]);

        try {
            $this->runner()->run($plan, $this->scope(), [self::CONNECTION], RowLimits::unlimited());
            $this->fail('Ожидалось исключение о действии без обработчика.');
        } catch (UnhandledActionException $e) {
            $this->assertSame('user_data_plan.unhandled_action', $e->errorCode());
            $this->assertSame(['testing.api_tokens' => 'anonymize'], $e->actions());
        }

        $this->assertSame(3, DB::table('active_goals')->count());
    }

    public function test_detach_rule_requires_cursor_key_column(): void
    {
        $plan = new CompiledUserDataPlan([
            UserDataRule::keep(new TableRef(self::CONNECTION, 'active_goals')),
            UserDataRule::detach(
                new TableRef(self::CONNECTION, 'api_tokens'),
                new InScope('user_id', ScopeKey::user()),
                ['user_id'],
                'uuid',
            ),
        ]);

        try {
            $this->runner()->run($plan, $this->scope(), [self::CONNECTION], RowLimits::unlimited());
            $this->fail('Ожидалось исключение об отсутствующем ключе курсора.');
        } catch (SchemaMismatchException $e) {
            $this->assertStringContainsString('uuid', implode('; ', $e->problems()));
        }
    }

    public function test_backup_only_rule_requires_cursor_key_column(): void
    {
        $rule = UserDataRule::backupOnly(
            new TableRef(self::CONNECTION, 'api_tokens'),
            new InScope('user_id', ScopeKey::user()),
            'uuid',
        );

        $this->assertContains('uuid', $rule->requiredColumns());
    }

    public function test_detach_of_not_nullable_column_stops_the_run_before_any_delete(): void
    {
        Schema::create('user_notes', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
        });

        DB::table('user_notes')->insert(['id' => 1, 'user_id' => 42]);

        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goals'),
                new InScope('user_id', ScopeKey::user()),
            ),
            UserDataRule::keep(new TableRef(self::CONNECTION, 'api_tokens')),
            UserDataRule::detach(
                new TableRef(self::CONNECTION, 'user_notes'),
                new InScope('user_id', ScopeKey::user()),
                ['user_id'],
            ),
        ]);

        try {
            $this->runner()->run($plan, $this->scope(), [self::CONNECTION], RowLimits::unlimited());
            $this->fail('Ожидалось исключение о колонке без NULL.');
        } catch (SchemaMismatchException $e) {
            $this->assertStringContainsString('user_notes', implode('; ', $e->problems()));
            $this->assertStringContainsString('NULL', implode('; ', $e->problems()));
        }

        $this->assertSame(3, DB::table('active_goals')->count());
        $this->assertSame(1, DB::table('user_notes')->where('user_id', 42)->count());
    }

    private function unsupportedSelector(): Selector
    {
        return new class implements Selector {
            public function type(): string
            {
                return 'unknown';
            }

            public function columns(): array
            {
                return ['user_id'];
            }

            public function scopeKeys(): array
            {
                return [];
            }
        };
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
