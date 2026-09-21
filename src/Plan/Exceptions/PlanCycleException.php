<?php

declare(strict_types=1);

namespace App\Plan\Exceptions;

/**
 * Зависимости правил образуют цикл, поэтому порядок удаления не определён.
 *
 * Дочерние строки отбираются подзапросом к родителю и обязаны удаляться раньше него.
 * При цикле такого порядка не существует: часть строк неизбежно осталась бы без
 * родителя — то есть превратилась бы ровно в тех сирот, ради которых строится план.
 */
final class PlanCycleException extends PlanException
{
    public const CODE = 'user_data_plan.cycle';

    /**
     * @var array<int, string>
     */
    private array $tableKeys;

    /**
     * @param array<int, string> $tableKeys Таблицы, оставшиеся неразрешёнными.
     */
    public function __construct(array $tableKeys)
    {
        $this->tableKeys = array_values($tableKeys);

        parent::__construct(
            'Циклическая зависимость правил: ' . implode(', ', $this->tableKeys) . '.'
        );
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * @return array<int, string>
     */
    public function tableKeys(): array
    {
        return $this->tableKeys;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'error_code' => self::CODE,
            'tables' => $this->tableKeys,
        ];
    }
}
