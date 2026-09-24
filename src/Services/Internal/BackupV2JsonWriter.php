<?php

declare(strict_types=1);

namespace App\Services\Internal;

use App\Exceptions\BackupSerializationException;
use App\ValueObjects\BackupHeader;
use App\ValueObjects\BackupTableSection;

/**
 * Пишет бэкап второй версии: шапка, затем секции с подключением и таблицей.
 *
 * Порядок ключей фиксирован — на нём держится потоковый разбор
 * (`BackupV2JsonStreamParser`). Секция без строк в файл не попадает: восстанавливать в
 * ней нечего.
 */
final class BackupV2JsonWriter
{
    public function __construct(
        private FileSystemAdapter $fileSystem,
        private BackupRowIterator $rows
    ) {
    }

    /**
     * @param resource $handle
     * @param iterable<int, BackupTableSection> $sections
     */
    public function write($handle, BackupHeader $header, iterable $sections): void
    {
        $this->fileSystem->write($handle, '{"@meta":' . $this->encode($header->toArray()) . ',"tables":[');

        $isFirstSection = true;

        foreach ($sections as $section) {
            if ($this->writeSection($handle, $section, $isFirstSection)) {
                $isFirstSection = false;
            }
        }

        $this->fileSystem->write($handle, ']}');
    }

    /**
     * Заголовок секции пишется лениво, при первой строке.
     *
     * @param resource $handle
     *
     * @return bool Секция записана (в ней была хотя бы одна строка).
     */
    private function writeSection($handle, BackupTableSection $section, bool $isFirstSection): bool
    {
        $started = false;

        foreach ($this->rows->encodedRows($section->sources()) as $row) {
            if (!$started) {
                $this->fileSystem->write($handle, ($isFirstSection ? '' : ',') . $this->sectionHead($section));
                $started = true;
            } else {
                $this->fileSystem->write($handle, ',');
            }

            $this->fileSystem->write($handle, $row);
        }

        if ($started) {
            $this->fileSystem->write($handle, ']}');
        }

        return $started;
    }

    private function sectionHead(BackupTableSection $section): string
    {
        return '{"connection":' . $this->encode($section->connection())
            . ',"table":' . $this->encode($section->table())
            . ',"rows":[';
    }

    /**
     * @param mixed $value
     */
    private function encode($value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new BackupSerializationException('Failed to encode backup header to JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }
}
