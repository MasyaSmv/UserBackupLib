<?php

declare(strict_types=1);

namespace App\Plan\Preflight;

/**
 * Итог сверки плана со схемой.
 *
 * Несёт то, что не является ошибкой, но должно быть видно: правила для таблиц, которых
 * в этой схеме нет. Между окружениями набор таблиц отличается — например, таблицы
 * авторизации могут отсутствовать на стенде, — и это не повод останавливать операцию:
 * удалять там нечего.
 */
final class PreflightReport
{
    /**
     * @var array<int, string>
     */
    private array $tablesMissingInSchema;

    /**
     * @param array<int, string> $tablesMissingInSchema
     */
    public function __construct(array $tablesMissingInSchema)
    {
        $this->tablesMissingInSchema = array_values($tablesMissingInSchema);
    }

    /**
     * Таблицы, описанные планом, но отсутствующие в схеме.
     *
     * @return array<int, string>
     */
    public function tablesMissingInSchema(): array
    {
        return $this->tablesMissingInSchema;
    }

    public function hasWarnings(): bool
    {
        return $this->tablesMissingInSchema !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tables_missing_in_schema' => $this->tablesMissingInSchema,
        ];
    }
}
