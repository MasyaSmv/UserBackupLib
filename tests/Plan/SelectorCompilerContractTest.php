<?php

declare(strict_types=1);

namespace Tests\Plan;

use App\Plan\Compiler\SelectorCompiler;
use App\Plan\Compiler\SelectorCompilerChain;
use App\Plan\Execution\RowChunkReader;
use App\Plan\ScopeKey;
use App\Plan\ScopeValues;
use App\Plan\Selector\InScope;
use App\Plan\Selector\Selector;
use App\Plan\TableRef;
use App\Plan\UserDataRule;
use App\ValueObjects\FilterValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Потребители компилятора обязаны соблюдать контракт `SelectorCompiler::apply()`.
 *
 * Интерфейс требует корневой компилятор пятым аргументом. Раньше чтение шло с четырьмя и
 * работало только потому, что у цепочки этот параметр необязательный: любая другая
 * реализация падала с ArgumentCountError (WS-3105).
 */
class SelectorCompilerContractTest extends TestCase
{
    private const CONNECTION = 'testing';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('active_goals', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
        });

        DB::table('active_goals')->insert([
            ['id' => 1, 'user_id' => 42],
            ['id' => 2, 'user_id' => 77],
        ]);
    }

    public function test_row_reader_passes_root_compiler_to_strict_implementation(): void
    {
        $reader = new RowChunkReader(new StrictRootCompiler(SelectorCompilerChain::default()));

        $rule = UserDataRule::backupAndDelete(
            new TableRef(self::CONNECTION, 'active_goals'),
            new InScope('user_id', ScopeKey::user()),
        );

        $scope = new ScopeValues([ScopeKey::USER => new FilterValues([42])]);

        $chunks = iterator_to_array(
            $reader->chunks(DB::connection(self::CONNECTION), self::CONNECTION, $rule, $scope, 10),
            false,
        );

        $this->assertEquals([[1]], $chunks);
    }
}

/**
 * Реализация без поблажек цепочки: корень обязателен, как в интерфейсе.
 */
final class StrictRootCompiler implements SelectorCompiler
{
    private SelectorCompiler $inner;

    public function __construct(SelectorCompiler $inner)
    {
        $this->inner = $inner;
    }

    public function supports(Selector $selector): bool
    {
        return $this->inner->supports($selector);
    }

    public function apply(
        Builder $query,
        Selector $selector,
        ScopeValues $scope,
        string $connection,
        SelectorCompiler $root
    ): void {
        $this->inner->apply($query, $selector, $scope, $connection, $root);
    }
}
