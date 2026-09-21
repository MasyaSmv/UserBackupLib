<?php

declare(strict_types=1);

namespace App\Plan\Exceptions;

use RuntimeException;

/**
 * Базовая ошибка плана удаления данных пользователя.
 *
 * Каждое отдельное условие ошибки — собственный класс с собственным кодом: новый вид
 * ошибки не должен требовать правки общего перечисления.
 */
abstract class PlanException extends RuntimeException
{
    /**
     * Машинный код ошибки для логов и клиентов.
     */
    abstract public function errorCode(): string;

    /**
     * Структурный контекст для записи в лог.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }
}
