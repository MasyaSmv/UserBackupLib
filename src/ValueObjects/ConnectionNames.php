<?php

declare(strict_types=1);

namespace App\ValueObjects;

final class ConnectionNames
{
    /**
     * @var array<int, string>
     */
    private array $names;

    /**
     * @param array<int, string> $names
     */
    public function __construct(array $names = [])
    {
        $normalized = [];

        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $normalized[] = $name;
        }

        $this->names = array_values(array_unique($normalized));
    }

    /**
     * @return array<int, string>
     */
    public function toArray(): array
    {
        return $this->names;
    }
}
