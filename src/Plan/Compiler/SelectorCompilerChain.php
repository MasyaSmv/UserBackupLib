<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Compiler;

use UserDataBackup\Plan\Exceptions\UnsupportedSelectorException;
use UserDataBackup\Plan\ScopeValues;
use UserDataBackup\Plan\Selector\Selector;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Подбирает компилятор под вид селектора и служит точкой входа для вложенных условий.
 *
 * Сам ничего не компилирует: добавление нового вида принадлежности сводится к
 * регистрации ещё одного компилятора в этой цепочке.
 */
final class SelectorCompilerChain implements SelectorCompiler
{
    /**
     * @var array<int, SelectorCompiler>
     */
    private array $compilers;

    /**
     * @param array<int, SelectorCompiler> $compilers
     */
    public function __construct(array $compilers)
    {
        foreach ($compilers as $compiler) {
            if (!$compiler instanceof SelectorCompiler) {
                throw new InvalidArgumentException('Цепочка принимает только компиляторы селекторов.');
            }
        }

        $this->compilers = array_values($compilers);
    }

    /**
     * Цепочка со штатным набором компиляторов.
     */
    public static function default(): self
    {
        return new self([
            new InScopeCompiler(),
            new EqualsCompiler(),
            new ExistsInParentCompiler(),
            new AnyOfCompiler(),
            new MorphReferenceCompiler(),
        ]);
    }

    public function supports(Selector $selector): bool
    {
        return $this->find($selector) !== null;
    }

    public function apply(
        Builder $query,
        Selector $selector,
        ScopeValues $scope,
        string $connection,
        ?SelectorCompiler $root = null
    ): void {
        $compiler = $this->find($selector);

        if ($compiler === null) {
            throw new UnsupportedSelectorException(get_class($selector), $selector->type());
        }

        $compiler->apply($query, $selector, $scope, $connection, $root ?? $this);
    }

    private function find(Selector $selector): ?SelectorCompiler
    {
        foreach ($this->compilers as $compiler) {
            if ($compiler->supports($selector)) {
                return $compiler;
            }
        }

        return null;
    }
}
