<?php

declare(strict_types=1);

namespace UserDataBackup\Contracts;

use UserDataBackup\ValueObjects\BackupHeader;
use UserDataBackup\ValueObjects\BackupTableSection;
use Generator;

/**
 * Контракт для сохранения и (опционально) шифрования бэкапов.
 */
interface FileStorageServiceInterface
{
    /**
     * Сохраняет поток данных в файл первой версии (без шапки), при необходимости шифруя
     * построчно.
     *
     * @param string   $filePath Путь к файлу без расширения .enc.
     * @param iterable $data     Поток данных вида таблица => iterable записей.
     * @param bool     $encrypt  Признак шифрования.
     *
     * @return string Итоговый путь до созданного файла (c .enc, если шифровали).
     */
    public function saveToFile(string $filePath, iterable $data, bool $encrypt = true): string;

    /**
     * Сохраняет бэкап второй версии: шапка с версиями и tenant, секции с подключением.
     *
     * @param string $filePath Путь к файлу без расширения .enc.
     * @param iterable<int, BackupTableSection> $sections Секции в порядке вставки при восстановлении.
     *
     * @return string Итоговый путь до созданного файла (c .enc, если шифровали).
     */
    public function saveBackup(string $filePath, BackupHeader $header, iterable $sections, bool $encrypt = true): string;

    /**
     * Потоково читает backup-файл любой версии и отдает записи по мере разбора.
     *
     * Каждая итерация возвращает массив вида:
     * - `table` => имя таблицы
     * - `row` => очередная запись таблицы
     * - `connection` => подключение из файла; `null` у файлов первой версии
     *
     * @param string $filePath Путь до `.json` или `.json.enc` файла.
     *
     * @return Generator<int, array{table: string, row: mixed, connection: string|null}>
     */
    public function streamBackupData(string $filePath): Generator;

    /**
     * Шапка файла. У файла первой версии — `BackupHeader::legacy()`.
     */
    public function readHeader(string $filePath): BackupHeader;
}
