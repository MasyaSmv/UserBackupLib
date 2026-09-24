<?php

declare(strict_types=1);

namespace Tests\Plan;

use App\Plan\Backup\PlanRowStreams;
use App\Plan\CompiledUserDataPlan;
use App\Plan\Execution\PlanExecutor;
use App\Plan\ScopeKey;
use App\Plan\ScopeValues;
use App\Plan\Selector\ExistsInParent;
use App\Plan\Selector\InScope;
use App\Plan\TableRef;
use App\Plan\UserDataRule;
use App\ValueObjects\BackupTableSection;
use App\ValueObjects\FilterValues;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Выгрузка по плану проверяется на настоящей базе и сверяется с удалением по тому же
 * плану: снимок обязан содержать ровно те строки, которые удаление унесёт.
 */
class PlanRowStreamsTest extends TestCase
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
            $table->integer('sum');
        });

        Schema::create('password_resets', static function (Blueprint $table): void {
            $table->string('email');
            $table->string('token');
        });

        Schema::create('users', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('retired_age');
        });
    }

    private function scope(): ScopeValues
    {
        return new ScopeValues([
            ScopeKey::USER => FilterValues::single(1),
            ScopeKey::EMAILS => new FilterValues(['owner@example.com']),
        ]);
    }

    private function plan(): CompiledUserDataPlan
    {
        $goals = new TableRef(self::CONNECTION, 'active_goals');

        return new CompiledUserDataPlan([
            UserDataRule::backupAndDelete($goals, new InScope('user_id', ScopeKey::user())),
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'active_goal_payments'),
                new ExistsInParent('goal_id', $goals, 'id', new InScope('user_id', ScopeKey::user())),
            ),
            UserDataRule::backupAndDelete(
                new TableRef(self::CONNECTION, 'password_resets'),
                new InScope('email', ScopeKey::emails()),
                'email',
            ),
            UserDataRule::keep(new TableRef(self::CONNECTION, 'api_tokens')),
            UserDataRule::backupOnly(
                new TableRef(self::CONNECTION, 'users'),
                new InScope('id', ScopeKey::user()),
            ),
        ]);
    }

    private function seedRows(): void
    {
        DB::table('active_goals')->insert([
            ['id' => 1, 'user_id' => 1],
            ['id' => 2, 'user_id' => 2],
        ]);

        DB::table('active_goal_payments')->insert([
            ['id' => 1, 'goal_id' => 1, 'sum' => 100],
            ['id' => 2, 'goal_id' => 1, 'sum' => 200],
            ['id' => 3, 'goal_id' => 2, 'sum' => 300],
        ]);

        DB::table('password_resets')->insert([
            ['email' => 'owner@example.com', 'token' => 'a'],
            ['email' => 'someone@example.com', 'token' => 'b'],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'retired_age' => 60],
            ['id' => 2, 'retired_age' => 65],
        ]);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function collect(PlanRowStreams $streams): array
    {
        $rows = [];

        foreach ($streams->sectionsFor($this->plan(), $this->scope()) as $section) {
            $table = $section->table();
            $rows[$table] = $rows[$table] ?? [];

            foreach ($section->sources() as $source) {
                foreach ($source as $row) {
                    $rows[$table][] = $row;
                }
            }
        }

        return $rows;
    }

    public function testStreamsCarryOwnedRowsIncludingChildAndContactTables(): void
    {
        $this->seedRows();

        $rows = $this->collect(PlanRowStreams::default(DB::getFacadeRoot()));

        // sqlite отдаёт ключи строками, mysql — числами: сравниваем приведёнными.
        $this->assertSame([1], array_map('intval', array_column($rows['active_goals'], 'id')));
        $this->assertSame([1, 2], array_map('intval', array_column($rows['active_goal_payments'], 'id')));
        $this->assertSame(['owner@example.com'], array_column($rows['password_resets'], 'email'));
    }

    /**
     * Подключение едет в файл вместе с таблицей: по нему восстановление вставляет строки,
     * не угадывая подключение по имени таблицы (WS-3066).
     */
    public function testEverySectionCarriesConnectionOfItsRule(): void
    {
        $sections = PlanRowStreams::default(DB::getFacadeRoot())->sectionsFor($this->plan(), $this->scope());

        $this->assertNotEmpty($sections);

        foreach ($sections as $section) {
            $this->assertSame(self::CONNECTION, $section->connection(), $section->table());
        }
    }

    public function testKeepTablesAreNotStreamed(): void
    {
        $this->seedRows();

        $this->assertArrayNotHasKey('api_tokens', $this->collect(PlanRowStreams::default(DB::getFacadeRoot())));
    }

    public function testChunkingReadsEveryRow(): void
    {
        DB::table('active_goals')->insert(['id' => 1, 'user_id' => 1]);

        $payments = [];
        for ($i = 1; $i <= 25; $i++) {
            $payments[] = ['id' => $i, 'goal_id' => 1, 'sum' => $i];
        }
        DB::table('active_goal_payments')->insert($payments);

        $rows = $this->collect(new PlanRowStreams(
            DB::getFacadeRoot(),
            \App\Plan\Compiler\SelectorCompilerChain::default(),
            new \App\Plan\Execution\KeysetCursor(),
            10,
        ));

        $this->assertCount(25, $rows['active_goal_payments']);
    }

    /**
     * Порядок ключей файла — порядок вставки при восстановлении: родитель обязан идти
     * раньше ребёнка, иначе вставка упирается во внешний ключ.
     */
    public function testParentTablesComeBeforeChildren(): void
    {
        $this->seedRows();

        $tables = array_map(
            static fn (BackupTableSection $section): string => $section->table(),
            PlanRowStreams::default(DB::getFacadeRoot())->sectionsFor($this->plan(), $this->scope()),
        );

        $this->assertLessThan(
            array_search('active_goal_payments', $tables, true),
            array_search('active_goals', $tables, true),
        );
    }

    /**
     * Строки backup_only уходят в снимок, но переживают удаление: восстановление вернёт
     * им значения upsert-ом, а без строки в `users` пользователь исчез бы.
     */
    public function testBackupOnlyRowsAreStreamedButSurviveDeletion(): void
    {
        $this->seedRows();

        $rows = $this->collect(PlanRowStreams::default(DB::getFacadeRoot()));

        $this->assertSame([1], array_map('intval', array_column($rows['users'], 'id')));

        PlanExecutor::default(DB::getFacadeRoot())->execute($this->plan(), $this->scope());

        $this->assertSame(
            [1, 2],
            array_map('intval', DB::table('users')->orderBy('id')->pluck('id')->all()),
        );
    }

    /**
     * Главный инвариант: сколько строк удаление унесёт, столько же выгрузка обязана
     * положить в снимок — плюс строки backup_only, которые остаются в базе.
     */
    public function testStreamedRowCountCoversDeletion(): void
    {
        $this->seedRows();

        $streamed = 0;
        foreach ($this->collect(PlanRowStreams::default(DB::getFacadeRoot())) as $rows) {
            $streamed += count($rows);
        }

        $deleted = PlanExecutor::default(DB::getFacadeRoot())
            ->execute($this->plan(), $this->scope())
            ->totalRows();

        // Одна строка сверх удалённых — та самая backup_only из `users`.
        $this->assertSame($deleted + 1, $streamed);
    }
}
