<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Exceptions;

/**
 * План описывает таблицы или колонки, которых в схеме нет.
 *
 * Означает, что схема ушла вперёд плана: колонку переименовали или таблицу удалили, а
 * правило осталось прежним. Операция прерывается до первого изменения данных — иначе
 * часть правил отработает, часть упадёт на середине, и состояние окажется промежуточным.
 */
final class SchemaMismatchException extends PlanException
{
    public const CODE = 'user_data_plan.schema_mismatch';

    /**
     * @var array<int, string>
     */
    private array $problems;

    /**
     * @param array<int, string> $problems Человекочитаемые описания расхождений.
     */
    public function __construct(array $problems)
    {
        $this->problems = array_values($problems);

        parent::__construct(
            'План разошёлся со схемой: ' . implode('; ', $this->problems) . '.'
        );
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * @return array<int, string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'error_code' => self::CODE,
            'problems' => $this->problems,
            'problems_count' => count($this->problems),
        ];
    }
}
