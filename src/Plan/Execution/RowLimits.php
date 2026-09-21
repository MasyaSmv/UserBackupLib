<?php

declare(strict_types=1);

namespace App\Plan\Execution;

use InvalidArgumentException;

/**
 * Предохранитель: сколько строк операция имеет право затронуть.
 *
 * Нужен потому, что ошибка в селекторе выглядит не как падение, а как успешная операция
 * с неожиданно большим охватом. Лимит превращает такую ошибку в остановку до удаления.
 */
final class RowLimits
{
    private ?int $perTable;

    private ?int $total;

    public function __construct(?int $perTable = null, ?int $total = null)
    {
        if ($perTable !== null && $perTable < 1) {
            throw new InvalidArgumentException('Лимит строк на таблицу должен быть положительным.');
        }

        if ($total !== null && $total < 1) {
            throw new InvalidArgumentException('Общий лимит строк должен быть положительным.');
        }

        $this->perTable = $perTable;
        $this->total = $total;
    }

    /**
     * Без ограничений — для сценариев, где охват заведомо большой и уже проверен.
     */
    public static function unlimited(): self
    {
        return new self();
    }

    public function perTable(): ?int
    {
        return $this->perTable;
    }

    public function total(): ?int
    {
        return $this->total;
    }

    public function hasAny(): bool
    {
        return $this->perTable !== null || $this->total !== null;
    }
}
