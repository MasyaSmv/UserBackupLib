<?php

declare(strict_types=1);

namespace App\Plan;

use App\Plan\Exceptions\NullCursorValueException;
use InvalidArgumentException;

/**
 * Колонки, по которым правило читает строки порциями.
 *
 * Ключ обязан быть уникальным: курсор продолжает чтение со следующего значения, и при
 * повторяющемся ключе граница порции внутри группы одинаковых значений теряла бы строки.
 * У таблиц с составным первичным ключом (`aton_portfolios_aggregated`:
 * `assignment_id + instrument_id + date`) курсор поэтому тоже составной (WS-3101).
 *
 * Порядок колонок — порядок сортировки. Лучше всего он совпадает с порядком колонок
 * первичного ключа: тогда чтение идёт по индексу.
 */
final class CursorKey
{
    /**
     * @var array<int, string>
     */
    private array $columns;

    /**
     * @param array<int, string> $columns
     */
    public function __construct(array $columns)
    {
        if ($columns === []) {
            throw new InvalidArgumentException('Ключ курсора не может быть пустым.');
        }

        foreach ($columns as $column) {
            if (!is_string($column) || $column === '') {
                throw new InvalidArgumentException('Колонка ключа курсора должна быть непустой строкой.');
            }
        }

        if (count(array_unique($columns)) !== count($columns)) {
            throw new InvalidArgumentException('Колонки ключа курсора не должны повторяться.');
        }

        $this->columns = array_values($columns);
    }

    public static function of(string ...$columns): self
    {
        return new self($columns);
    }

    /**
     * Одиночная колонка строкой — сокращение для самого частого случая, `id`.
     *
     * @param string|self $key
     */
    public static function from($key): self
    {
        return $key instanceof self ? $key : self::of($key);
    }

    /**
     * @return array<int, string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    public function isComposite(): bool
    {
        return count($this->columns) > 1;
    }

    /**
     * Значения ключа из строки выборки, в порядке колонок ключа.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     *
     * @throws NullCursorValueException NULL в ключе: курсор не может продвинуться.
     */
    public function valuesOf(array $row, TableRef $tableRef): array
    {
        $values = [];

        foreach ($this->columns as $column) {
            $value = $row[$column] ?? null;

            if ($value === null) {
                throw new NullCursorValueException($tableRef->key(), $column);
            }

            $values[$column] = $value;
        }

        return $values;
    }

    public function describe(): string
    {
        return implode(' + ', $this->columns);
    }
}
