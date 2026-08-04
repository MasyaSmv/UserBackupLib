<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * Спецификация коррелированного подзапроса для фильтрации таблицы.
 *
 * Позволяет заменить огромный whereIn(field, [десятки тысяч литералов]) на
 * `field IN (SELECT {column} FROM {table} WHERE {column} = {value})`, не «зашивая»
 * доменное знание об источнике в generic-пакет: приложение само сообщает, из какой
 * таблицы и по какой колонке брать идентификаторы.
 *
 * Значением может быть сам userId (обычный случай active_id → actives.user_id).
 */
final class FilterSubquery
{
    private string $table;

    private string $selectColumn;

    private string $whereColumn;

    /**
     * @var int|string
     */
    private $whereValue;

    public function __construct(string $table, string $selectColumn, string $whereColumn, int|string $whereValue)
    {
        $this->table = $table;
        $this->selectColumn = $selectColumn;
        $this->whereColumn = $whereColumn;
        $this->whereValue = $whereValue;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function selectColumn(): string
    {
        return $this->selectColumn;
    }

    public function whereColumn(): string
    {
        return $this->whereColumn;
    }

    public function whereValue(): int|string
    {
        return $this->whereValue;
    }
}
