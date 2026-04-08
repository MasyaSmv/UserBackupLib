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
     * Сохраняет ранее собранный бэкап в указанный файл.
     *
     * @param string $filePath Путь, куда нужно сохранить бэкап.
     * @param bool $encrypt Включить шифрование файла.
     *
     * @return string Путь к итоговому файлу.
     */
    public function saveBackupToFile(string $filePath, bool $encrypt = true): string;
}
