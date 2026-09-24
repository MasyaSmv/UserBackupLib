<?php

declare(strict_types=1);

namespace Tests\Plan;

use App\Plan\Backup\PlanRowStreams;
use App\Plan\CompiledUserDataPlan;
use App\Plan\CursorKey;
use App\Plan\Exceptions\NullCursorValueException;
use App\Plan\Exceptions\SchemaMismatchException;
use App\Plan\Execution\PlanExecutor;
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
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Курсор по составному уникальному ключу (WS-3101).
 *
 * Модель — `aton_portfolios_aggregated`: первичный ключ `assignment_id + instrument_id +
 * date`, на одну привязку десятки тысяч строк. Курсор по одной `assignment_id` отдавал в
 * бэкап одну порцию из группы, а удаление по списку значений уносило группу целиком.
 * Здесь размер порции 2, а у привязки 7 строк: граница порции проходит внутри группы и
 * внутри одной даты.
 */
class CompositeCursorTest extends TestCase
{
    private const CONNECTION = 'testing';

    private const CHUNK = 2;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('portfolios_aggregated', static function (Blueprint $table): void {
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('instrument_id');
            $table->date('date');
            $table->integer('quantity');
            $table->primary(['assignment_id', 'instrument_id', 'date']);
        });

        $rows = [];

        foreach ([10, 20, 30] as $instrument) {
            foreach (['2026-01-01', '2026-01-02'] as $date) {
                $rows[] = ['assignment_id' => 1, 'instrument_id' => $instrument, 'date' => $date, 'quantity' => 1];
            }
        }

        $rows[] = ['assignment_id' => 1, 'instrument_id' => 40, 'date' => '2026-01-01', 'quantity' => 1];
        $rows[] = ['assignment_id' => 2, 'instrument_id' => 10, 'date' => '2026-01-01', 'quantity' => 1];
        $rows[] = ['assignment_id' => 2, 'instrument_id' => 20, 'date' => '2026-01-01', 'quantity' => 1];

        DB::table('portfolios_aggregated')->insert($rows);
    }

    private function scope(): ScopeValues
    {
        return new ScopeValues([ScopeKey::USER => new FilterValues([1])]);
    }

    private function plan(CursorKey $key): CompiledUserDataPlan
    {
        return new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'portfolios_aggregated'),
                new InScope('assignment_id', ScopeKey::user()),
                $key,
            ),
        ]);
    }

    private function compositeKey(): CursorKey
    {
        return CursorKey::of('assignment_id', 'instrument_id', 'date');
    }

    /**
     * @return array<int, string>
     */
    private function backedUpKeys(CompiledUserDataPlan $plan): array
    {
        $sections = PlanRowStreams::default(DB::getFacadeRoot(), self::CHUNK)->sectionsFor($plan, $this->scope());
        $keys = [];

        foreach ($sections as $section) {
            if ($section->table() !== 'portfolios_aggregated') {
                continue;
            }

            foreach ($section->sources() as $stream) {
                foreach ($stream as $row) {
                    $keys[] = $row['assignment_id'] . '|' . $row['instrument_id'] . '|' . $row['date'];
                }
            }
        }

        sort($keys);

        return $keys;
    }

    public function test_backup_reads_every_row_across_chunk_boundary_inside_one_group(): void
    {
        $keys = $this->backedUpKeys($this->plan($this->compositeKey()));

        $this->assertCount(7, $keys, 'Выгрузка обязана отдать всю группу, а не одну порцию');
        $this->assertCount(7, array_unique($keys), 'Строки на границе порции не должны дублироваться');
    }

    public function test_delete_removes_exactly_what_backup_read(): void
    {
        $plan = $this->plan($this->compositeKey());
        $backedUp = $this->backedUpKeys($plan);

        $report = PlanExecutor::default(DB::getFacadeRoot(), self::CHUNK)->execute($plan, $this->scope());

        $this->assertSame(count($backedUp), $report->totalRows());
        $this->assertSame(0, DB::table('portfolios_aggregated')->where('assignment_id', 1)->count());
        $this->assertSame(2, DB::table('portfolios_aggregated')->where('assignment_id', 2)->count());
    }

    public function test_dry_run_counts_every_row_of_the_group(): void
    {
        $report = PlanExecutor::default(DB::getFacadeRoot(), self::CHUNK)
            ->execute($this->plan($this->compositeKey()), $this->scope(), true);

        $this->assertSame(7, $report->totalRows());
        $this->assertSame(9, DB::table('portfolios_aggregated')->count());
    }

    public function test_preflight_rejects_cursor_that_does_not_cover_a_unique_key(): void
    {
        try {
            (new PlanPreflight(DB::getFacadeRoot()))->check(
                $this->plan(CursorKey::of('assignment_id')),
                [self::CONNECTION],
            );

            $this->fail('Ожидалось исключение о неуникальном курсоре.');
        } catch (SchemaMismatchException $e) {
            $this->assertStringContainsString('не покрывает', implode('; ', $e->problems()));
        }
    }

    public function test_preflight_rejects_nullable_cursor_column(): void
    {
        Schema::create('password_codes', static function (Blueprint $table): void {
            $table->string('email')->nullable()->unique();
            $table->string('token');
        });

        $plan = new CompiledUserDataPlan([
            UserDataRule::keep(new TableRef(self::CONNECTION, 'portfolios_aggregated')),
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'password_codes'),
                new InScope('email', ScopeKey::user()),
                'email',
            ),
        ]);

        try {
            (new PlanPreflight(DB::getFacadeRoot()))->check($plan, [self::CONNECTION]);

            $this->fail('Ожидалось исключение о nullable-курсоре.');
        } catch (SchemaMismatchException $e) {
            $this->assertStringContainsString('допускает NULL', implode('; ', $e->problems()));
        }
    }

    public function test_null_cursor_value_stops_reading_instead_of_looping(): void
    {
        Schema::create('loose_rows', static function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('position')->nullable();
        });

        DB::table('loose_rows')->insert([['user_id' => 1, 'position' => null]]);

        $plan = new CompiledUserDataPlan([
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'loose_rows'),
                new InScope('user_id', ScopeKey::user()),
                'position',
            ),
        ]);

        $this->expectException(NullCursorValueException::class);

        // Намеренно в обход preflight: исключение — последний рубеж.
        PlanExecutor::default(DB::getFacadeRoot(), self::CHUNK)->execute($plan, $this->scope());
    }

    public function test_cursor_key_rejects_empty_and_repeated_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CursorKey::of('assignment_id', 'assignment_id');
    }
}
