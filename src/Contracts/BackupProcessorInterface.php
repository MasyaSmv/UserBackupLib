<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Отвечает за агрегирование пользовательских данных в процессе бэкапа.
 */
interface BackupProcessorInterface
{
    /**
     * Добавляет поток данных конкретной таблицы в общий набор бэкапа.
     *
     * @param string   $table Имя таблицы.
     * @param iterable $data  Поток записей таблицы (чанки или курсор).
     */
    public function appendUserData(string $table, iterable $data): void;

    /**
     * Возвращает накопленные данные по всем таблицам.
     *
     * @return array<string, array<int, iterable>> Ассоциативный массив таблица => список потоков.
     */
    public function getUserData(): array;

    /**
     * Очищает накопленные данные, чтобы начать новую сессию бэкапа.
     */
    public function clearUserData(): void;
}
