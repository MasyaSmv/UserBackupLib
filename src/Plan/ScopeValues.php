<?php

declare(strict_types=1);

namespace App\Plan;

use App\ValueObjects\FilterValues;
use InvalidArgumentException;

/**
 * Значения скоупа пользователя, разложенные по именованным наборам.
 *
 * Наполняет наборы приложение: оно знает, как получить субсчета, активы и tenant-ключ
 * каталога. Селектор лишь называет нужный набор, поэтому знание о формате значений — в
 * частности о строковом `{tenant}-{id}` — не растекается по правилам.
 */
final class ScopeValues
{
    /**
     * @var array<string, FilterValues>
     */
    private array $values;

    /**
     * @param array<string, FilterValues> $values Ключ — значение ScopeKey.
     */
    public function __construct(array $values)
    {
        foreach ($values as $key => $filterValues) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Ключ набора значений должен быть строкой.');
            }

            // Валидируем имя набора: опечатка в ключе иначе молча даст пустую выборку.
            ScopeKey::fromString($key);

            if (!$filterValues instanceof FilterValues) {
                throw new InvalidArgumentException('Набор ' . $key . ' должен быть FilterValues.');
            }
        }

        $this->values = $values;
    }

    public function has(ScopeKey $key): bool
    {
        return isset($this->values[$key->value()]);
    }

    /**
     * Значения набора. Отсутствующий набор — пустой, а не ошибка: селектор мог сослаться
     * на набор, который для данного пользователя пуст (нет субсчетов, нет активов).
     */
    public function get(ScopeKey $key): FilterValues
    {
        return $this->values[$key->value()] ?? new FilterValues();
    }

    public function isEmptyFor(ScopeKey $key): bool
    {
        return $this->get($key)->isEmpty();
    }

    /**
     * @return array<string, FilterValues>
     */
    public function all(): array
    {
        return $this->values;
    }
}
