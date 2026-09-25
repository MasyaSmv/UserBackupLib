<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Exceptions;

/**
 * В схеме есть таблицы, для которых план не содержит правила.
 *
 * Операция прерывается до первого изменения данных. Молчаливый пропуск неизвестной
 * таблицы — ровно тот сценарий, из-за которого данные пользователя оставались в базе, а
 * системные строки удалялись; поэтому неизвестная таблица блокирует работу, а не
 * трактуется как «не относится к пользователю».
 */
final class PlanIncompleteException extends PlanException
{
    public const CODE = 'user_data_plan.incomplete';

    /**
     * @var array<int, string>
     */
    private array $tableKeys;

    /**
     * @param array<int, string> $tableKeys
     */
    public function __construct(array $tableKeys)
    {
        $this->tableKeys = array_values($tableKeys);

        parent::__construct(
            'План не покрывает таблицы: ' . implode(', ', $this->tableKeys) . '.'
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
            'tables_count' => count($this->tableKeys),
        ];
    }
}
