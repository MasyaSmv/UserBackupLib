<?php

declare(strict_types=1);

namespace App\Plan\Exceptions;

/**
 * Сухой прогон показал больше строк, чем разрешено предохранителем.
 *
 * Срабатывает до боевого прохода. Ошибка в селекторе не выглядит как падение: операция
 * завершается успешно, просто захватывает лишнее. Лимит — единственное, что превращает
 * такой случай в остановку.
 */
final class RowLimitExceededException extends PlanException
{
    public const CODE = 'user_data_plan.row_limit_exceeded';

    private string $scope;

    private int $rows;

    private int $limit;

    /**
     * @param string $scope Ключ таблицы либо `total` для общего лимита.
     */
    public function __construct(string $scope, int $rows, int $limit)
    {
        $this->scope = $scope;
        $this->rows = $rows;
        $this->limit = $limit;

        parent::__construct(
            'Охват ' . $scope . ': ' . $rows . ' строк при лимите ' . $limit . '.'
        );
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'error_code' => self::CODE,
            'scope' => $this->scope,
            'rows' => $this->rows,
            'limit' => $this->limit,
        ];
    }
}
