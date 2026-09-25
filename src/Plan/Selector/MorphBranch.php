<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Selector;

use InvalidArgumentException;

/**
 * Одна ветка полиморфной связи: набор значений колонки типа и проверка идентификатора.
 *
 * Значений именно набор, а не одно: за годы у одной и той же сущности успевает
 * смениться алиас, и в таблицах остаются строки, записанные прежней версией кода.
 * Правило, знающее только текущий алиас, оставит их в базе навсегда.
 */
final class MorphBranch
{
    /**
     * @var array<int, string>
     */
    private array $types;

    private Selector $selector;

    /**
     * @param array<int, string> $types Все значения колонки типа, означающие эту сущность.
     */
    public function __construct(array $types, Selector $selector)
    {
        if ($types === []) {
            throw new InvalidArgumentException('Ветка полиморфной связи требует хотя бы одно значение типа.');
        }

        foreach ($types as $type) {
            if (!is_string($type) || $type === '') {
                throw new InvalidArgumentException('Значение полиморфного типа должно быть непустой строкой.');
            }
        }

        $this->types = array_values(array_unique($types));
        $this->selector = $selector;
    }

    /**
     * @param string ...$types
     */
    public static function of(Selector $selector, string ...$types): self
    {
        return new self($types, $selector);
    }

    /**
     * @return array<int, string>
     */
    public function types(): array
    {
        return $this->types;
    }

    public function selector(): Selector
    {
        return $this->selector;
    }
}
