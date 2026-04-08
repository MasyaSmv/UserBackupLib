<?php

declare(strict_types=1);

namespace App\ValueObjects;

final class TableQueryParameters
{
    /**
     * @var array<string, FilterValues>
     */
    private array $filters;

    /**
     * @param array<string, FilterValues> $filters
     */
    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    /**
     * @param array<string, array<int, int|string>> $filters
     */
    public static function fromArray(array $filters): self
    {
        $normalized = [];

        foreach ($filters as $field => $values) {
            if (!is_string($field) || !is_array($values)) {
                continue;
            }

            $normalized[$field] = new FilterValues($values);
        }

        return new self($normalized);
    }

    public function valuesFor(string $field): FilterValues
    {
        return $this->filters[$field] ?? new FilterValues();
    }

    public function valuesForWithFallback(string $field, string $fallbackField): FilterValues
    {
        $primary = $this->valuesFor($field);

        if (!$primary->isEmpty()) {
            return $primary;
        }

        return $this->valuesFor($fallbackField);
    }

    /**
     * @return array<string, array<int, int|string>>
     */
    public function toArray(): array
    {
        $result = [];

        foreach ($this->filters as $field => $values) {
            $result[$field] = $values->toArray();
        }

        return $result;
    }
}
