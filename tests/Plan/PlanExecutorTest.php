<?php

declare(strict_types=1);

namespace Tests\Plan;

use UserDataBackup\Plan\CompiledUserDataPlan;
use UserDataBackup\Plan\Execution\PlanExecutor;
use UserDataBackup\Plan\ScopeKey;
use UserDataBackup\Plan\ScopeValues;
use UserDataBackup\Plan\Selector\AnyOf;
use UserDataBackup\Plan\Selector\ExistsInParent;
use UserDataBackup\Plan\Selector\InScope;
use UserDataBackup\Plan\TableRef;
use UserDataBackup\Plan\UserDataRule;
use UserDataBackup\ValueObjects\FilterValues;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Исполнение плана проверяется на настоящей базе: важно не только что строки исчезли,
 * но и что чужие остались, а дочерние не пережили родителя.
 */
class PlanExecutorTest extends TestCase
{
    private const CONNECTION = 'testing';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('active_goals', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
        });

        Schema::create('active_goal_payments', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('goal_id');
        });

        Schema::create('api_tokens', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
        });

        Schema::create('relations', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id1')->nullable();
            $table->integer('user_id2')->nullable();
        });
    }

    /**
     * Идентификаторы таблицы как целые числа.
     *
     * Через array_map, а не Collection::map('intval'): map передаёт вторым аргументом ключ,
     * а intval принимает его как систему счисления — второй элемент превращается в 0.
     *
     * @return array<int, int>
     */
    private function intIds(string $table): array
    {
        return array_map('intval', DB::table($table)->orderBy('id')->pluck('id')->all());
    }

    private function executor(int $chunkSize = PlanExecutor::DEFAULT_CHUNK_SIZE): PlanExecutor
    {
        return PlanExecutor::default(DB::getFacadeRoot(), $chunkSize);
    }

    private function scope(int $userId): ScopeValues
    {
        return new ScopeValues([
            ScopeKey::USER => new FilterValues([$userId]),
        ]);
    }

    private function goalsRef(): TableRef
    {
        return new TableRef(self::CONNECTION, 'active_goals');
    }

    private function goalsWithPaymentsPlan(): CompiledUserDataPlan
    {
        $goals = $this->goalsRef();
        $userOwned = new InScope('user_id', ScopeKey::user());

        return new CompiledUserDataPlan([
            UserDataRule::backupAndDelete($goals, $userOwned),
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goal_payments'),
                new ExistsInParent('goal_id', $goals, 'id', $userOwned),
            ),
            UserDataRule::keep(new TableRef(self::CONNECTION, 'api_tokens')),
        ]);
    }

    public function test_child_rows_do_not_outlive_their_parent(): void
    {
        DB::table('active_goals')->insert([
            ['id' => 1, 'user_id' => 42],
            ['id' => 2, 'user_id' => 77],
        ]);

        DB::table('active_goal_payments')->insert([
            ['id' => 10, 'goal_id' => 1],
            ['id' => 11, 'goal_id' => 1],
            ['id' => 12, 'goal_id' => 2],
        ]);

        $this->executor()->execute($this->goalsWithPaymentsPlan(), $this->scope(42));

        $this->assertSame([2], $this->intIds('active_goals'));
        $this->assertSame(
            [12],
            $this->intIds('active_goal_payments'),
            'Платежи удалённой цели обязаны исчезнуть вместе с ней',
        );
    }

    public function test_keep_tables_are_left_untouched(): void
    {
        DB::table('api_tokens')->insert([
            ['id' => 1, 'user_id' => 42],
            ['id' => 2, 'user_id' => 77],
        ]);

        $this->executor()->execute($this->goalsWithPaymentsPlan(), $this->scope(42));

        $this->assertSame(
            [1, 2],
            $this->intIds('api_tokens'),
            'Сброс портфеля не должен трогать авторизацию',
        );
    }

    public function test_rows_are_deleted_in_chunks_smaller_than_the_table(): void
    {
        DB::table('active_goals')->insert(['id' => 1, 'user_id' => 42]);

        $payments = [];

        for ($id = 1; $id <= 25; $id++) {
            $payments[] = ['id' => $id, 'goal_id' => 1];
        }

        DB::table('active_goal_payments')->insert($payments);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $report = $this->executor(10)->execute($this->goalsWithPaymentsPlan(), $this->scope(42));

        $this->assertSame(0, DB::table('active_goal_payments')->count());
        $this->assertGreaterThanOrEqual(
            6,
            $queries,
            'Порция в 10 строк на 25 строках обязана дать несколько чтений и удалений, а не один DELETE',
        );

        $payments = $this->resultFor($report, 'active_goal_payments');
        $this->assertSame(25, $payments->rows());
    }

    public function test_dry_run_counts_rows_without_touching_them(): void
    {
        DB::table('active_goals')->insert([
            ['id' => 1, 'user_id' => 42],
            ['id' => 2, 'user_id' => 77],
        ]);

        DB::table('active_goal_payments')->insert([
            ['id' => 10, 'goal_id' => 1],
            ['id' => 11, 'goal_id' => 1],
        ]);

        $report = $this->executor()->execute($this->goalsWithPaymentsPlan(), $this->scope(42), true);

        $this->assertTrue($report->isDryRun());
        $this->assertSame(3, $report->totalRows());
        $this->assertSame(2, DB::table('active_goals')->count());
        $this->assertSame(2, DB::table('active_goal_payments')->count());
    }

    public function test_dry_run_and_real_run_agree_on_counts(): void
    {
        DB::table('active_goals')->insert([
            ['id' => 1, 'user_id' => 42],
            ['id' => 2, 'user_id' => 42],
            ['id' => 3, 'user_id' => 77],
        ]);

        DB::table('active_goal_payments')->insert([
            ['id' => 10, 'goal_id' => 1],
            ['id' => 11, 'goal_id' => 2],
            ['id' => 12, 'goal_id' => 3],
        ]);

        $plan = $this->goalsWithPaymentsPlan();

        $dryRun = $this->executor()->execute($plan, $this->scope(42), true);
        $real = $this->executor()->execute($plan, $this->scope(42));

        $this->assertSame($dryRun->totalRows(), $real->totalRows());
    }

    public function test_detach_clears_the_link_and_keeps_the_row(): void
    {
        DB::table('relations')->insert([
            ['id' => 1, 'user_id1' => 42, 'user_id2' => 77],
            ['id' => 2, 'user_id1' => 77, 'user_id2' => 99],
        ]);

        $plan = new CompiledUserDataPlan([
            UserDataRule::detach(
                new TableRef(self::CONNECTION, 'relations'),
                AnyOf::of(
                    new InScope('user_id1', ScopeKey::user()),
                    new InScope('user_id2', ScopeKey::user()),
                ),
                ['user_id1'],
            ),
        ]);

        $this->executor()->execute($plan, $this->scope(42));

        $rows = DB::table('relations')->orderBy('id')->get();

        $this->assertCount(2, $rows, 'Строка связи принадлежит и второму пользователю, её нельзя удалять');
        $this->assertNull($rows[0]->user_id1);
        $this->assertSame(77, (int) $rows[0]->user_id2);
        $this->assertSame(77, (int) $rows[1]->user_id1);
    }

    public function test_report_carries_plan_version_and_only_touched_tables(): void
    {
        DB::table('active_goals')->insert(['id' => 1, 'user_id' => 42]);
        DB::table('api_tokens')->insert(['id' => 1, 'user_id' => 42]);

        $plan = $this->goalsWithPaymentsPlan();
        $report = $this->executor()->execute($plan, $this->scope(42));

        $this->assertSame($plan->version(), $report->planVersion());

        $tables = array_map(
            static fn (array $row): string => $row['table'],
            $report->toArray()['tables'],
        );

        $this->assertSame(['active_goals'], $tables);
    }

    public function test_user_without_data_changes_nothing(): void
    {
        DB::table('active_goals')->insert(['id' => 1, 'user_id' => 77]);

        $report = $this->executor()->execute($this->goalsWithPaymentsPlan(), $this->scope(42));

        $this->assertSame(0, $report->totalRows());
        $this->assertSame(1, DB::table('active_goals')->count());
    }

    private function resultFor($report, string $table)
    {
        foreach ($report->results() as $result) {
            if ($result->tableRef()->table() === $table) {
                return $result;
            }
        }

        $this->fail('В отчёте нет таблицы ' . $table);
    }
}
