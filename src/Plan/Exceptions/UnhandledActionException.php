<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Exceptions;

/**
 * Для изменяющего действия правила нет обработчика.
 *
 * Пропуск такого правила выглядел бы как успешная операция, после которой данные
 * остались на месте. Поэтому это ошибка, и она поднимается до первого изменения данных:
 * обработчики подбираются для всего плана сразу (WS-3105).
 */
final class UnhandledActionException extends PlanException
{
    public const CODE = 'user_data_plan.unhandled_action';

    /**
     * @var array<string, string> tableKey => действие
     */
    private array $actions;

    /**
     * @param array<string, string> $actions tableKey => действие без обработчика.
     */
    public function __construct(array $actions)
    {
        $this->actions = $actions;

        $described = [];

        foreach ($actions as $tableKey => $action) {
            $described[] = $tableKey . ' (' . $action . ')';
        }

        parent::__construct('Нет обработчика для действий: ' . implode(', ', $described) . '.');
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * @return array<string, string>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    public function context(): array
    {
        return [
            'error_code' => self::CODE,
            'actions' => $this->actions,
        ];
    }
}
