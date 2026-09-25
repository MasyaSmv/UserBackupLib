<?php

declare(strict_types=1);

namespace UserDataBackup\Services\Internal;

use UserDataBackup\Exceptions\BackupSerializationException;

/**
 * Разворачивает источники строк таблицы в поток JSON-строк для записи в файл.
 *
 * Общий для обеих версий формата: источник может быть порцией строк или одной строкой,
 * объекты приводятся к массивам.
 */
final class BackupRowIterator
{
    /**
     * @param iterable $sources
     * @return iterable<int, string> Закодированные строки.
     */
    public function encodedRows(iterable $sources): iterable
    {
        foreach ($sources as $source) {
            if (!is_iterable($source)) {
                yield $this->encode($source);
                continue;
            }

            foreach ($source as $row) {
                yield $this->encode($row);
            }
        }
    }

    /**
     * @param mixed $row
     */
    private function encode($row): string
    {
        $encoded = json_encode(is_object($row) ? (array) $row : $row, JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new BackupSerializationException('Failed to encode backup row to JSON: ' . json_last_error_msg());
        }

        return $encoded;
    }
}
