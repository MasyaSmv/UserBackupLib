<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Compiler;

use UserDataBackup\Plan\ScopeValues;
use UserDataBackup\Plan\Selector\MorphReference;
use UserDataBackup\Plan\Selector\Selector;
use Illuminate\Database\Query\Builder;

/**
 * Компилирует полиморфную пару: для каждого известного типа — своя проверка идентификатора.
 *
 * Условие типа и условие идентификатора всегда идут вместе в одной скобке. Разорвать их
 * нельзя: `item_id = 5` существует в каждом типе, и проверка одного идентификатора
 * захватила бы чужие строки.
 */
final class MorphReferenceCompiler implements SelectorCompiler
{
    public function supports(Selector $selector): bool
    {
        return $selector instanceof MorphReference;
    }

    public function apply(
        Builder $query,
        Selector $selector,
        ScopeValues $scope,
        string $connection,
        SelectorCompiler $root
    ): void {
        /** @var MorphReference $selector */
        $query->where(static function (Builder $group) use ($selector, $scope, $connection, $root): void {
            foreach ($selector->branches() as $morphBranch) {
                $group->orWhere(
                    static function (Builder $branch) use (
                        $selector,
                        $morphBranch,
                        $scope,
                        $connection,
                        $root
                    ): void {
                        $branch->whereIn($selector->typeColumn(), $morphBranch->types());

                        $root->apply($branch, $morphBranch->selector(), $scope, $connection, $root);
                    }
                );
            }
        });
    }
}
