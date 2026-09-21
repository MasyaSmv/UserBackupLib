<?php

declare(strict_types=1);

namespace App\Plan\Compiler;

use App\Plan\ScopeValues;
use App\Plan\Selector\InScope;
use App\Plan\Selector\Selector;
use Illuminate\Database\Query\Builder;

/**
 * Компилирует вхождение колонки в набор значений скоупа.
 *
 * Пустой набор означает «таких строк нет»: условие делается заведомо ложным, а не
 * опускается. Опущенное условие превратило бы выборку во «все строки таблицы» — при
 * удалении это стоило бы чужих данных.
 */
final class InScopeCompiler implements SelectorCompiler
{
    public function supports(Selector $selector): bool
    {
        return $selector instanceof InScope;
    }

    public function apply(
        Builder $query,
        Selector $selector,
        ScopeValues $scope,
        string $connection,
        SelectorCompiler $root
    ): void {
        /** @var InScope $selector */
        $values = $scope->get($selector->scopeKey())->toArray();

        if ($values === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($selector->column(), $values);
    }
}
