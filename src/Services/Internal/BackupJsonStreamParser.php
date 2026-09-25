<?php

declare(strict_types=1);

namespace UserDataBackup\Services\Internal;

use UserDataBackup\Exceptions\BackupFormatException;
use Generator;

class BackupJsonStreamParser
{
    public const INCOMPLETE_JSON_VALUE = '__INCOMPLETE_JSON_VALUE__';

    /**
     * Дефолтный лимит незавершённого хвоста буфера — 128 MiB.
     * Одна строка бэкапа заметно меньше; лимит защищает от OOM на битом файле
     * без разделителей (буфер иначе рос бы до конца файла) и от аномально
     * больших записей.
     */
    private const DEFAULT_MAX_RECORD_SIZE = 134217728;

    /**
     * @param int $maxRecordSize Максимальный размер непотреблённого хвоста буфера в байтах.
     */
    public function __construct(private int $maxRecordSize = self::DEFAULT_MAX_RECORD_SIZE)
    {
    }

    /**
     * Потоковый разбор бэкапа формата {"table":[row,row,...],...}.
     *
     * Производительность: буфер читается через integer-курсор ($pos), а не
     * срезается с головы на каждой строке. Потреблённый префикс отбрасывается
     * ОДИН раз на входной чанк (ленивая компактизация), поэтому суммарный объём
     * копирования линеен по размеру файла — O(n), а не O(n^2).
     *
     * @param iterable<string> $chunks
     * @return Generator<int, BackupStreamEntry>
     */
    public function parse(iterable $chunks, string $filePath): Generator
    {
        $buffer = '';
        $pos = 0;
        $state = 'start_object';
        $currentTable = null;

        foreach ($chunks as $chunk) {
            // Ленивая компактизация: отбрасываем прочитанный префикс один раз на
            // чанк. Остаток — только незавершённая запись, поэтому копия дешёвая.
            if ($pos > 0) {
                $buffer = substr($buffer, $pos);
                $pos = 0;
            }

            $buffer .= $chunk;
            $len = strlen($buffer);

            if ($len > $this->maxRecordSize) {
                throw new BackupFormatException(sprintf(
                    'Backup record in "%s" exceeds max size of %d bytes',
                    $filePath,
                    $this->maxRecordSize,
                ));
            }

            while (true) {
                $this->skipWhitespace($buffer, $pos, $len);

                switch ($state) {
                    case 'start_object':
                        if ($pos >= $len) {
                            break 2;
                        }

                        if ($buffer[$pos] !== '{') {
                            throw new BackupFormatException(sprintf('Invalid backup format in "%s": expected object start', $filePath));
                        }

                        $pos++;
                        $state = 'table_or_end';
                        break;

                    case 'table_or_end':
                        if ($pos >= $len) {
                            break 2;
                        }

                        if ($buffer[$pos] === '}') {
                            $pos++;
                            $state = 'done';
                            break 3;
                        }

                        $currentTable = $this->consumeJsonString($buffer, $pos, $len, $filePath);
                        if ($currentTable === null) {
                            break 2;
                        }

                        $state = 'table_separator';
                        break;

                    case 'table_separator':
                        if ($pos >= $len) {
                            break 2;
                        }

                        if ($buffer[$pos] !== ':') {
                            throw new BackupFormatException(sprintf('Invalid backup format in "%s": expected ":" after table name', $filePath));
                        }

                        $pos++;
                        $state = 'array_start';
                        break;

                    case 'array_start':
                        if ($pos >= $len) {
                            break 2;
                        }

                        if ($buffer[$pos] !== '[') {
                            throw new BackupFormatException(sprintf('Invalid backup format in "%s": expected "[" after table name', $filePath));
                        }

                        $pos++;
                        $state = 'row_or_array_end';
                        break;

                    case 'row_or_array_end':
                        if ($pos >= $len) {
                            break 2;
                        }

                        if ($buffer[$pos] === ']') {
                            $pos++;
                            $currentTable = null;
                            $state = 'table_delimiter_or_end';
                            break;
                        }

                        $delimiter = null;
                        $row = $this->consumeJsonValue($buffer, $pos, $len, $filePath, $delimiter);
                        if ($row === self::INCOMPLETE_JSON_VALUE) {
                            break 2;
                        }

                        /** @var string $currentTable */
                        yield new BackupStreamEntry($currentTable, $row);

                        if ($delimiter === ',') {
                            $pos++;
                            $state = 'row_or_array_end';
                            break;
                        }

                        if ($delimiter === ']') {
                            $pos++;
                            $currentTable = null;
                            $state = 'table_delimiter_or_end';
                            break;
                        }

                    case 'table_delimiter_or_end':
                        if ($pos >= $len) {
                            break 2;
                        }

                        if ($buffer[$pos] === ',') {
                            $pos++;
                            $state = 'table_or_end';
                            break;
                        }

                        if ($buffer[$pos] === '}') {
                            $pos++;
                            $state = 'done';
                            break 3;
                        }

                        throw new BackupFormatException(sprintf('Invalid backup format in "%s": expected "," or "}" after table payload', $filePath));
                }
            }
        }

        $len = strlen($buffer);
        $this->skipWhitespace($buffer, $pos, $len);

        if ($state !== 'done' || $pos < $len) {
            throw new BackupFormatException(sprintf('Unexpected end of backup stream in "%s"', $filePath));
        }
    }

    /**
     * Продвигает курсор через пробельные символы (эквивалент ltrim по умолчанию).
     */
    public function skipWhitespace(string &$buffer, int &$pos, int $len): void
    {
        while ($pos < $len) {
            $char = $buffer[$pos];

            if ($char === ' ' || $char === "\n" || $char === "\r" || $char === "\t" || $char === "\0" || $char === "\x0B") {
                $pos++;
                continue;
            }

            break;
        }
    }

    /**
     * Читает JSON-строку начиная с $pos. При успехе двигает $pos за закрывающую
     * кавычку и возвращает декодированное значение; при незавершённой строке
     * возвращает null и оставляет $pos без изменений.
     */
    public function consumeJsonString(string &$buffer, int &$pos, int $len, string $filePath): ?string
    {
        if ($pos >= $len) {
            return null;
        }

        if ($buffer[$pos] !== '"') {
            throw new BackupFormatException(sprintf('Invalid backup format in "%s": expected JSON string', $filePath));
        }

        $escaped = false;

        for ($i = $pos + 1; $i < $len; $i++) {
            $char = $buffer[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $token = substr($buffer, $pos, $i + 1 - $pos);
                $decoded = json_decode($token, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_string($decoded)) {
                    throw new BackupFormatException(
                        sprintf('Failed to decode JSON string from backup "%s": %s', $filePath, json_last_error_msg()),
                    );
                }

                $pos = $i + 1;

                return $decoded;
            }
        }

        return null;
    }

    /**
     * Читает JSON-значение начиная с $pos до top-level разделителя ("," или "]").
     * При успехе двигает $pos НА позицию разделителя и заполняет $delimiter; при
     * незавершённом значении возвращает маркер и не двигает $pos.
     *
     * @return mixed
     */
    public function consumeJsonValue(string &$buffer, int &$pos, int $len, string $filePath, ?string &$delimiter = null)
    {
        $inString = false;
        $escaped = false;
        $objectDepth = 0;
        $arrayDepth = 0;
        $delimiter = null;

        for ($i = $pos; $i < $len; $i++) {
            $char = $buffer[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '{') {
                $objectDepth++;
                continue;
            }

            if ($char === '}') {
                $objectDepth--;
                continue;
            }

            if ($char === '[') {
                $arrayDepth++;
                continue;
            }

            if ($char === ']') {
                if ($objectDepth === 0 && $arrayDepth === 0) {
                    $delimiter = ']';

                    return $this->decodeJsonToken(substr($buffer, $pos, $i - $pos), $filePath, $pos, $i);
                }

                $arrayDepth--;
                continue;
            }

            if ($char === ',' && $objectDepth === 0 && $arrayDepth === 0) {
                $delimiter = ',';

                return $this->decodeJsonToken(substr($buffer, $pos, $i - $pos), $filePath, $pos, $i);
            }
        }

        return self::INCOMPLETE_JSON_VALUE;
    }

    /**
     * Декодирует извлечённый токен и переставляет курсор на позицию разделителя.
     *
     * @return mixed
     */
    public function decodeJsonToken(string $token, string $filePath, int &$pos, int $offset)
    {
        $decoded = json_decode($token, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BackupFormatException(
                sprintf('Failed to decode JSON token from backup "%s": %s', $filePath, json_last_error_msg()),
            );
        }

        $pos = $offset;

        return $decoded;
    }
}
