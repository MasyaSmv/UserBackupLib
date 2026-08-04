<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Contracts\TableFilter;
use Illuminate\Database\Query\Builder;

/**
 * Фильтрация по литеральному списку значений: `WHERE field IN (v1, v2, ...)`.
 */
final class LiteralFilter implements TableFilter
{
    /**
     * @var array<int, int|string>
     */
    private array $values;

    /**
     * @param array<int, int|string> $values
     */
    public function __construct(array $values)
    {
        $this->values = array_values($values);
    }

    public function applyTo(Builder $query, string $field): void
    {
        $query->whereIn($field, $this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
