<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Контракт фасада для сценария выгрузки и сохранения бэкапа.
 */
interface UserBackupServiceInterface
{
    /**
     * Собирает данные пользователя по всем таблицам и подключениям.
     *
     * @return array<string, array<int, iterable>>
     */
    public function fetchAllUserData(): array;

    /**
     * Сохраняет ранее собранный бэкап в файл.
     *
     * @param bool $encrypt Включить шифрование файла.
     *
     * @return string Путь к файлу.
     */
    public function saveBackupToFile(bool $encrypt = true): string;
}
