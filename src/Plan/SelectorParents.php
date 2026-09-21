<?php

declare(strict_types=1);

namespace App\Plan;

use App\Plan\Selector\AnyOf;
use App\Plan\Selector\ExistsInParent;
use App\Plan\Selector\MorphBranch;
use App\Plan\Selector\MorphReference;
use App\Plan\Selector\Selector;

/**
 * Собирает таблицы-родители, от которых зависит селектор.
 *
 * Зависимости не объявляются правилом руками: они уже содержатся в селекторе, а
 * дублирование списка рано или поздно разойдётся с реальным условием. Порядок удаления
 * строит план, опираясь на результат этого обхода.
 */
final class SelectorParents
{
    /**
     * @return array<string, TableRef> Ключ — TableRef::key(), значения уникальны.
     */
    public function collect(Selector $selector): array
    {
        if ($selector instanceof ExistsInParent) {
            $parents = [$selector->parent()->key() => $selector->parent()];

            return $parents + $this->collect($selector->parentSelector());
        }

        if ($selector instanceof AnyOf) {
            return $this->collectFromMany($selector->selectors());
        }

        if ($selector instanceof MorphReference) {
            return $this->collectFromMany(array_map(
                static fn (MorphBranch $branch): Selector => $branch->selector(),
                $selector->branches(),
            ));
        }

        return [];
    }

    /**
     * @param array<int, Selector> $selectors
     *
     * @return array<string, TableRef>
     */
    private function collectFromMany(array $selectors): array
    {
        $parents = [];

        foreach ($selectors as $selector) {
            foreach ($this->collect($selector) as $key => $parent) {
                $parents[$key] = $parent;
            }
        }

        return $parents;
    }
}
