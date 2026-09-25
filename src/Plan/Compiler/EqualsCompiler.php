<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Compiler;

use UserDataBackup\Plan\ScopeValues;
use UserDataBackup\Plan\Selector\Equals;
use UserDataBackup\Plan\Selector\Selector;
use Illuminate\Database\Query\Builder;

/**
 * Компилирует сравнение колонки с фиксированным значением.
 */
final class EqualsCompiler implements SelectorCompiler
{
    public function supports(Selector $selector): bool
    {
        return $selector instanceof Equals;
    }

    public function apply(
        Builder $query,
        Selector $selector,
        ScopeValues $scope,
        string $connection,
        SelectorCompiler $root
    ): void {
        /** @var Equals $selector */
        $value = $selector->value();

        if ($value === null) {
            $query->whereNull($selector->column());

            return;
        }

        $query->where($selector->column(), '=', $value);
    }
}
