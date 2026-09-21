<?php

declare(strict_types=1);

namespace App\Plan\Compiler;

use App\Plan\Exceptions\CrossConnectionParentException;
use App\Plan\ScopeValues;
use App\Plan\Selector\ExistsInParent;
use App\Plan\Selector\Selector;
use Illuminate\Database\Query\Builder;

/**
 * Компилирует принадлежность через родителя в коррелированный подзапрос.
 *
 * Подзапрос, а не заранее выбранный список идентификаторов: у активного пользователя
 * счёт активов идёт на тысячи, и `whereIn` с таким списком перестаёт быть запросом.
 */
final class ExistsInParentCompiler implements SelectorCompiler
{
    public function supports(Selector $selector): bool
    {
        return $selector instanceof ExistsInParent;
    }

    public function apply(
        Builder $query,
        Selector $selector,
        ScopeValues $scope,
        string $connection,
        SelectorCompiler $root
    ): void {
        /** @var ExistsInParent $selector */
        $parent = $selector->parent();

        if ($parent->connection() !== $connection) {
            throw new CrossConnectionParentException($connection, $parent->key());
        }

        $query->whereIn(
            $selector->column(),
            static function (Builder $subQuery) use ($selector, $scope, $connection, $root, $parent): void {
                $subQuery->select($selector->parentColumn())->from($parent->table());

                $root->apply($subQuery, $selector->parentSelector(), $scope, $connection, $root);
            }
        );
    }
}
