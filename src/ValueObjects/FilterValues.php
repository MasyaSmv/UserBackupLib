<?php

declare(strict_types=1);

namespace App\ValueObjects;

final class FilterValues
{
    /**
     * @var array<int, int|string>
     */
    private array $values;

    /**
     * @param array<int, int|string> $values
     */
    public function __construct(array $values = [])
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                continue;
            }

            $normalized[] = $value;
        }

        $this->values = array_values(array_unique($normalized, SORT_REGULAR));
    }

    public static function single(int|string $value): self
    {
        return new self([$value]);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @return array<int, int|string>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return array<int, array<int, int|string>>
     */
    public function chunk(int $size): array
    {
        return array_chunk($this->values, $size);
    }
}
