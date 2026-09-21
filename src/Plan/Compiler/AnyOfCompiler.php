<?php

declare(strict_types=1);

namespace App\Plan\Compiler;

use App\Plan\ScopeValues;
use App\Plan\Selector\AnyOf;
use App\Plan\Selector\Selector;
use Illuminate\Database\Query\Builder;

/**
 * Компилирует несколько условий принадлежности в одну скобку с OR.
 *
 * Одним запросом, а не несколькими последовательными: независимые выборки по каждому
 * полю вернули бы одну и ту же строку несколько раз и положили бы её в backup дважды.
 */
final class AnyOfCompiler implements SelectorCompiler
{
    public function supports(Selector $selector): bool
    {
        return $selector instanceof AnyOf;
    }

    public function apply(
        Builder $query,
        Selector $selector,
        ScopeValues $scope,
        string $connection,
        SelectorCompiler $root
    ): void {
        /** @var AnyOf $selector */
        $query->where(static function (Builder $group) use ($selector, $scope, $connection, $root): void {
            foreach ($selector->selectors() as $nested) {
                $group->orWhere(
                    static function (Builder $branch) use ($nested, $scope, $connection, $root): void {
                        $root->apply($branch, $nested, $scope, $connection, $root);
                    }
                );
            }
        });
    }
}
