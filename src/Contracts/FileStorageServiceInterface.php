<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Контракт для сохранения и (опционально) шифрования бэкапов.
 */
interface FileStorageServiceInterface
{
    /**
     * Сохраняет поток данных в файл, при необходимости шифруя построчно.
     *
     * @param string   $filePath Путь к файлу без расширения .enc.
     * @param iterable $data     Поток данных вида таблица => iterable записей.
     * @param bool     $encrypt  Признак шифрования.
     *
     * @return string Итоговый путь до созданного файла (c .enc, если шифровали).
     */
    public function saveToFile(string $filePath, iterable $data, bool $encrypt = true): string;
}
