<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Contracts\TableFilter;
use Illuminate\Database\Query\Builder;

/**
 * Фильтрация коррелированным подзапросом: `WHERE field IN (SELECT col FROM t WHERE c = v)`.
 *
 * Заменяет whereIn([десятки тысяч литералов]) одним компактным запросом: СУБД строит
 * семи-джойн по индексу вместо разбора мегабайтного IN-списка на каждой таблице.
 */
final class SubqueryFilter implements TableFilter
{
    private FilterSubquery $spec;

    public function __construct(FilterSubquery $spec)
    {
        $this->spec = $spec;
    }

    public function applyTo(Builder $query, string $field): void
    {
        $spec = $this->spec;

        $query->whereIn($field, static function (Builder $sub) use ($spec): void {
            $sub->select($spec->selectColumn())
                ->from($spec->table())
                ->where($spec->whereColumn(), $spec->whereValue());
        });
    }

    public function isEmpty(): bool
    {
        // Подзапрос сам решит, сколько строк отобрать; пустым его считать нельзя.
        return false;
    }
}
