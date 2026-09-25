<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Selector;

use UserDataBackup\Plan\ScopeKey;
use InvalidArgumentException;

/**
 * Строка принадлежит пользователю, если выполнено хотя бы одно из вложенных условий.
 *
 * Нужен там, где полей связи несколько: у сделки это `active_id`, `from_account_id` и
 * `to_account_id` одновременно. Прежний движок брал одно поле по приоритету и терял
 * строки, у которых заполнено только другое.
 *
 * Условия объединяются в один запрос: последовательные независимые выборки по каждому
 * полю положили бы одну и ту же строку в backup дважды.
 */
final class AnyOf implements Selector
{
    public const TYPE = 'any_of';

    /**
     * @var array<int, Selector>
     */
    private array $selectors;

    /**
     * @param array<int, Selector> $selectors
     */
    public function __construct(array $selectors)
    {
        if (count($selectors) < 2) {
            throw new InvalidArgumentException('AnyOf требует минимум два условия.');
        }

        foreach ($selectors as $selector) {
            if (!$selector instanceof Selector) {
                throw new InvalidArgumentException('AnyOf принимает только селекторы.');
            }
        }

        $this->selectors = array_values($selectors);
    }

    /**
     * @param Selector ...$selectors
     */
    public static function of(Selector ...$selectors): self
    {
        return new self($selectors);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    /**
     * @return array<int, Selector>
     */
    public function selectors(): array
    {
        return $this->selectors;
    }

    /**
     * @return array<int, string>
     */
    public function columns(): array
    {
        $columns = [];

        foreach ($this->selectors as $selector) {
            foreach ($selector->columns() as $column) {
                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return array<int, ScopeKey>
     */
    public function scopeKeys(): array
    {
        $keys = [];

        foreach ($this->selectors as $selector) {
            foreach ($selector->scopeKeys() as $key) {
                $keys[$key->value()] = $key;
            }
        }

        return array_values($keys);
    }
}
