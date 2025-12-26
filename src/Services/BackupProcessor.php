<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\BackupProcessorInterface;

class BackupProcessor implements BackupProcessorInterface
{
    /**
     * @var array<string, array<int, iterable>>
     */
    protected array $userData = [];

    public function appendUserData(string $table, iterable $data): void
    {
        if (!isset($this->userData[$table])) {
            $this->userData[$table] = [];
        }

        $this->userData[$table][] = $data;
    }

    /**
     * Возвращает накопленные данные, не итерируя их сразу для экономии памяти.
     *
     * @return array<string, array<int, iterable>>
     */
    public function getUserData(): array
    {
        return $this->userData;
    }

    /**
     * Сбрасывает внутренний буфер.
     */
    public function clearUserData(): void
    {
        $this->userData = [];
    }
}
