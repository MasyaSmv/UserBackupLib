<?php

declare(strict_types=1);

namespace App\Plan\Selector;

use InvalidArgumentException;

/**
 * Строка отбирается по фиксированному значению колонки, не зависящему от пользователя.
 *
 * Нужен как уточнение внутри составных селекторов: например, разряд полиморфной пары
 * (`item_type = 'Active'`) или признак, отделяющий пользовательские строки от системных
 * в общей таблице.
 */
final class Equals implements Selector
{
    public const TYPE = 'equals';

    private string $column;

    /**
     * @var int|string|bool|null
     */
    private $value;

    /**
     * @param int|string|bool|null $value
     */
    public function __construct(string $column, $value)
    {
        if ($column === '') {
            throw new InvalidArgumentException('Имя колонки не может быть пустым.');
        }

        if (!is_int($value) && !is_string($value) && !is_bool($value) && $value !== null) {
            throw new InvalidArgumentException('Значение сравнения должно быть скаляром или null.');
        }

        $this->column = $column;
        $this->value = $value;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function column(): string
    {
        return $this->column;
    }

    /**
     * @return int|string|bool|null
     */
    public function value()
    {
        return $this->value;
    }

    /**
     * @return array<int, string>
     */
    public function columns(): array
    {
        return [$this->column];
    }

    /**
     * @return array<int, \App\Plan\ScopeKey>
     */
    public function scopeKeys(): array
    {
        return [];
    }
}
