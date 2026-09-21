<?php

declare(strict_types=1);

namespace App\Plan\Selector;

use App\Plan\ScopeKey;
use InvalidArgumentException;

/**
 * Строка принадлежит пользователю, если значение колонки входит в набор скоупа.
 *
 * Самый частый вид связи: `user_id` в наборе пользователя, `account_id` в наборе
 * субсчетов, `active_id` в наборе активов.
 */
final class InScope implements Selector
{
    public const TYPE = 'in_scope';

    private string $column;

    private ScopeKey $scopeKey;

    public function __construct(string $column, ScopeKey $scopeKey)
    {
        if ($column === '') {
            throw new InvalidArgumentException('Имя колонки не может быть пустым.');
        }

        $this->column = $column;
        $this->scopeKey = $scopeKey;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function column(): string
    {
        return $this->column;
    }

    public function scopeKey(): ScopeKey
    {
        return $this->scopeKey;
    }

    /**
     * @return array<int, string>
     */
    public function columns(): array
    {
        return [$this->column];
    }

    /**
     * @return array<int, ScopeKey>
     */
    public function scopeKeys(): array
    {
        return [$this->scopeKey];
    }
}
