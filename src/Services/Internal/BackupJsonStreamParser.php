<?php

declare(strict_types=1);

namespace App\Services\Internal;

use App\Exceptions\BackupFormatException;
use Generator;

class BackupJsonStreamParser
{
    public const INCOMPLETE_JSON_VALUE = '__INCOMPLETE_JSON_VALUE__';

    /**
     * @param iterable<string> $chunks
     * @return Generator<int, array{table: string, row: mixed}>
     */
    public function parse(iterable $chunks, string $filePath): Generator
    {
        $buffer = '';
        $state = 'start_object';
        $currentTable = null;

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            while (true) {
                $this->skipWhitespace($buffer);

                switch ($state) {
                    case 'start_object':
                        if ($buffer === '') {
                            break 2;
                        }

                        if ($buffer[0] !== '{') {
                            throw new BackupFormatException(sprintf('Invalid backup format in "%s": expected object start', $filePath));
                        }

                        $buffer = substr($buffer, 1);
                        $state = 'table_or_end';
                        break;

                    case 'table_or_end':
                        if ($buffer === '') {
                            break 2;
                        }

                        if ($buffer[0] === '}') {
                            $buffer = substr($buffer, 1);
                            $state = 'done';
                            break 3;
                        }

                        $currentTable = $this->consumeJsonString($buffer, $filePath);
                        if ($currentTable === null) {
                            break 2;
                        }

                        $state = 'table_separator';
                        break;

                    case 'table_separator':
                        if ($buffer === '') {
                            break 2;
                        }

                        if ($buffer[0] !== ':') {
                            throw new BackupFormatException(sprintf('Invalid backup format in "%s": expected ":" after table name', $filePath));
                        }

                        $buffer = substr($buffer, 1);
                        $state = 'array_start';
                        break;

                    case 'array_start':
                        if ($buffer === '') {
                            break 2;
                        }

                        if ($buffer[0] !== '[') {
                            throw new BackupFormatException(sprintf('Invalid backup format in "%s": expected "[" after table name', $filePath));
                        }

                        $buffer = substr($buffer, 1);
                        $state = 'row_or_array_end';
                        break;

                    case 'row_or_array_end':
                        if ($buffer === '') {
                            break 2;
                        }

                        if ($buffer[0] === ']') {
                            $buffer = substr($buffer, 1);
                            $currentTable = null;
                            $state = 'table_delimiter_or_end';
                            break;
                        }

                        $delimiter = null;
                        $row = $this->consumeJsonValue($buffer, $filePath, $delimiter);
                        if ($row === self::INCOMPLETE_JSON_VALUE) {
                            break 2;
                        }

                        /** @var string $currentTable */
                        yield new BackupStreamEntry($currentTable, $row);

                        if ($delimiter === ',') {
                            $buffer = substr($buffer, 1);
                            $state = 'row_or_array_end';
                            break;
                        }

                        if ($delimiter === ']') {
                            $buffer = substr($buffer, 1);
                            $currentTable = null;
                            $state = 'table_delimiter_or_end';
                            break;
                        }

                    case 'table_delimiter_or_end':
                        if ($buffer === '') {
                            break 2;
                        }

                        if ($buffer[0] === ',') {
                            $buffer = substr($buffer, 1);
                            $state = 'table_or_end';
                            break;
                        }

                        if ($buffer[0] === '}') {
                            $buffer = substr($buffer, 1);
                            $state = 'done';
                            break 3;
                        }

                        throw new BackupFormatException(sprintf('Invalid backup format in "%s": expected "," or "}" after table payload', $filePath));
                }
            }
        }

        $this->skipWhitespace($buffer);

        if ($state !== 'done' || $buffer !== '') {
            throw new BackupFormatException(sprintf('Unexpected end of backup stream in "%s"', $filePath));
        }
    }

    public function skipWhitespace(string &$buffer): void
    {
        $buffer = ltrim($buffer);
    }

    public function consumeJsonString(string &$buffer, string $filePath): ?string
    {
        if ($buffer === '') {
            return null;
        }

        if ($buffer[0] !== '"') {
            throw new BackupFormatException(sprintf('Invalid backup format in "%s": expected JSON string', $filePath));
        }

        $escaped = false;
        $length = strlen($buffer);

        for ($i = 1; $i < $length; $i++) {
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
                $token = substr($buffer, 0, $i + 1);
                $decoded = json_decode($token, true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_string($decoded)) {
                    throw new BackupFormatException(
                        sprintf('Failed to decode JSON string from backup "%s": %s', $filePath, json_last_error_msg()),
                    );
                }

                $buffer = substr($buffer, $i + 1);

                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return mixed
     */
    public function consumeJsonValue(string &$buffer, string $filePath, ?string &$delimiter = null)
    {
        $inString = false;
        $escaped = false;
        $objectDepth = 0;
        $arrayDepth = 0;
        $delimiter = null;
        $length = strlen($buffer);

        for ($i = 0; $i < $length; $i++) {
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
                    return $this->decodeJsonToken(substr($buffer, 0, $i), $filePath, $buffer, $i);
                }

                $arrayDepth--;
                continue;
            }

            if ($char === ',' && $objectDepth === 0 && $arrayDepth === 0) {
                $delimiter = ',';
                return $this->decodeJsonToken(substr($buffer, 0, $i), $filePath, $buffer, $i);
            }
        }

        return self::INCOMPLETE_JSON_VALUE;
    }

    /**
     * @return mixed
     */
    public function decodeJsonToken(string $token, string $filePath, string &$buffer, int $offset)
    {
        $decoded = json_decode($token, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BackupFormatException(
                sprintf('Failed to decode JSON token from backup "%s": %s', $filePath, json_last_error_msg()),
            );
        }

        $buffer = substr($buffer, $offset);

        return $decoded;
    }
}
