<?php

declare(strict_types=1);

namespace App\Plan\Compiler;

use App\Plan\ScopeValues;
use App\Plan\Selector\Selector;
use Illuminate\Database\Query\Builder;

/**
 * Превращает один вид селектора в условие запроса.
 *
 * Компилятор на каждый вид селектора отдельный: новый вид принадлежности добавляется
 * новым классом, а существующие не правятся. Это единственное место, где декларация
 * встречается с Query Builder.
 */
interface SelectorCompiler
{
    /**
     * Умеет ли компилятор работать с этим селектором.
     */
    public function supports(Selector $selector): bool;

    /**
     * Навешивает условие на запрос.
     *
     * Условие обязано быть сгруппированным: селекторы комбинируются через OR, и
     * несгруппированное условие изменило бы смысл соседних.
     *
     * @param SelectorCompiler $root Компилятор верхнего уровня — через него составные
     *                               селекторы компилируют вложенные, не зная их видов.
     */
    public function apply(
        Builder $query,
        Selector $selector,
        ScopeValues $scope,
        string $connection,
        SelectorCompiler $root
    ): void;
}
