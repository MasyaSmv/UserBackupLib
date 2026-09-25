<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Exceptions;

/**
 * В колонке ключа курсора встретился NULL.
 *
 * Курсор продолжает чтение со значения последней строки; NULL не больше и не меньше ничего,
 * поэтому чтение либо зацикливалось бы на той же порции, либо обрывалось, теряя остаток
 * выгрузки (WS-3101). Preflight не пропускает nullable-колонки в ключе, и это исключение —
 * последний рубеж для вызовов в обход него.
 */
final class NullCursorValueException extends PlanException
{
    public const CODE = 'user_data_plan.null_cursor_value';

    private string $tableKey;

    private string $column;

    public function __construct(string $tableKey, string $column)
    {
        $this->tableKey = $tableKey;
        $this->column = $column;

        parent::__construct('В ' . $tableKey . ' колонка курсора ' . $column . ' содержит NULL.');
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function context(): array
    {
        return [
            'error_code' => self::CODE,
            'table' => $this->tableKey,
            'column' => $this->column,
        ];
    }
}
