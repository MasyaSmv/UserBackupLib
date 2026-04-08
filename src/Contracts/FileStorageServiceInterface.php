<?php

declare(strict_types=1);

namespace App\Contracts;

use Generator;

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

    /**
     * Потоково читает backup-файл и отдает записи по мере разбора.
     *
     * Каждая итерация возвращает массив вида:
     * - `table` => имя таблицы
     * - `row` => очередная запись таблицы
     *
     * @param string $filePath Путь до `.json` или `.json.enc` файла.
     *
     * @return Generator<int, array{table: string, row: mixed}>
     */
    public function streamBackupData(string $filePath): Generator;
}
