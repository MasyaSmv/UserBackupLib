<?php

declare(strict_types=1);

namespace App\Plan\Exceptions;

/**
 * Для селектора не нашлось компилятора.
 *
 * Означает, что новый вид принадлежности объявили, но не научили превращать в запрос.
 * Операция прерывается: выполнить правило частично хуже, чем не выполнить вовсе.
 */
final class UnsupportedSelectorException extends PlanException
{
    public const CODE = 'user_data_plan.unsupported_selector';

    private string $selectorClass;

    private string $selectorType;

    public function __construct(string $selectorClass, string $selectorType)
    {
        $this->selectorClass = $selectorClass;
        $this->selectorType = $selectorType;

        parent::__construct('Нет компилятора для селектора ' . $selectorClass . ' (' . $selectorType . ').');
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'error_code' => self::CODE,
            'selector_class' => $this->selectorClass,
            'selector_type' => $this->selectorType,
        ];
    }
}
