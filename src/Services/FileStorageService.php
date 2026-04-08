<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FileStorageServiceInterface;
use Generator;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Потоковая запись/чтение бэкапов с опциональным шифрованием чанками.
 */
class FileStorageService implements FileStorageServiceInterface
{
    /**
     * Расшифровывает файл с данными пользователя.
     *
     * Поддерживает два формата:
     *  1) Старый: целиком зашифрованная JSON-строка (одна строка в файле).
     *  2) Новый: файл состоит из нескольких строк, каждая строка — шифротекст отдельного чанка JSON.
     *
     * @param string $encryptedFilePath
     *
     * @return array
     * @throws RuntimeException
     */
    public static function decryptFile(string $encryptedFilePath): array
    {
        $data = [];

        foreach ((new self())->streamBackupData($encryptedFilePath) as $entry) {
            $data[$entry['table']][] = $entry['row'];
        }

        return $data;
    }

    public function saveToFile(string $filePath, iterable $data, bool $encrypt = true): string
    {
        $tempPath = $this->createDirectoryAndTempFile($filePath);

        $tempHandle = fopen($tempPath, 'wb');

        if (!$tempHandle) {
            throw new RuntimeException("Unable to open temp file for writing: {$tempPath}");
        }

        try {
            $this->writeJsonStream($tempHandle, $data);
        } finally {
            fclose($tempHandle);
        }

        if ($encrypt) {
            $encryptedPath = $filePath . '.enc';
            $this->encryptTempFile($tempPath, $encryptedPath);

            return $encryptedPath;
        }

        if (!rename($tempPath, $filePath)) {
            throw new RuntimeException("Unable to move temp file to {$filePath}");
        }

        return $filePath;
    }

    public function streamBackupData(string $filePath): Generator
    {
        $buffer = '';
        $state = 'start_object';
        $currentTable = null;

        foreach (self::iterateDecryptedChunks($filePath) as $chunk) {
            $buffer .= $chunk;

            while (true) {
                $this->skipWhitespace($buffer);

                switch ($state) {
                    case 'start_object':
                        if ($buffer === '') {
                            break 2;
                        }

                        if ($buffer[0] !== '{') {
                            throw new RuntimeException(sprintf('Invalid backup format in "%s": expected object start', $filePath));
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
                            throw new RuntimeException(sprintf('Invalid backup format in "%s": expected ":" after table name', $filePath));
                        }

                        $buffer = substr($buffer, 1);
                        $state = 'array_start';
                        break;

                    case 'array_start':
                        if ($buffer === '') {
                            break 2;
                        }

                        if ($buffer[0] !== '[') {
                            throw new RuntimeException(sprintf('Invalid backup format in "%s": expected "[" after table name', $filePath));
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

                        $row = $this->consumeJsonValue($buffer, $filePath);
                        if ($row === self::INCOMPLETE_JSON_VALUE) {
                            break 2;
                        }

                        /** @var string $currentTable */
                        yield [
                            'table' => $currentTable,
                            'row' => $row,
                        ];

                        $state = 'row_delimiter_or_array_end';
                        break;

                    case 'row_delimiter_or_array_end':
                        if ($buffer === '') {
                            break 2;
                        }

                        if ($buffer[0] === ',') {
                            $buffer = substr($buffer, 1);
                            $state = 'row_or_array_end';
                            break;
                        }

                        if ($buffer[0] === ']') {
                            $buffer = substr($buffer, 1);
                            $currentTable = null;
                            $state = 'table_delimiter_or_end';
                            break;
                        }

                        throw new RuntimeException(sprintf('Invalid backup format in "%s": expected "," or "]" after row', $filePath));

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

                        throw new RuntimeException(sprintf('Invalid backup format in "%s": expected "," or "}" after table payload', $filePath));

                    case 'done':
                        break 3;

                    default:
                        throw new RuntimeException(sprintf('Unknown parser state "%s"', $state));
                }
            }
        }

        $this->skipWhitespace($buffer);

        if ($state !== 'done' || $buffer !== '') {
            throw new RuntimeException(sprintf('Unexpected end of backup stream in "%s"', $filePath));
        }
    }

    private const INCOMPLETE_JSON_VALUE = '__INCOMPLETE_JSON_VALUE__';

    /**
     * @param resource $handle
     * @param iterable<string, iterable> $data
     */
    private function writeJsonStream($handle, iterable $data): void
    {
        $this->writeChunk($handle, '{');

        $isFirstTable = true;

        foreach ($data as $table => $tableChunks) {
            if (!$isFirstTable) {
                $this->writeChunk($handle, ',');
            }

            $this->writeChunk($handle, json_encode((string) $table, JSON_UNESCAPED_UNICODE) . ':[');

            $isFirstRow = true;

            foreach ($this->iterateTableRows($tableChunks) as $row) {
                if (!$isFirstRow) {
                    $this->writeChunk($handle, ',');
                }

                $encodedRow = json_encode($row, JSON_UNESCAPED_UNICODE);
                if ($encodedRow === false) {
                    throw new RuntimeException('Failed to encode backup row to JSON: ' . json_last_error_msg());
                }

                $this->writeChunk($handle, $encodedRow);
                $isFirstRow = false;
            }

            $this->writeChunk($handle, ']');
            $isFirstTable = false;
        }

        $this->writeChunk($handle, '}');
    }

    /**
     * @param iterable $tableChunks
     * @return iterable<array|\JsonSerializable|scalar|null>
     */
    private function iterateTableRows(iterable $tableChunks): iterable
    {
        foreach ($tableChunks as $chunk) {
            if (is_iterable($chunk)) {
                foreach ($chunk as $row) {
                    yield $this->normalizeRow($row);
                }
            } else {
                yield $this->normalizeRow($chunk);
            }
        }
    }

    private function normalizeRow($row)
    {
        if (is_object($row)) {
            return (array) $row;
        }

        return $row;
    }

    private function writeChunk($handle, string $chunk): void
    {
        if (fwrite($handle, $chunk) === false) {
            throw new RuntimeException('Failed to write backup chunk to file');
        }
    }

    /**
     * Создаёт директорию и временный файл для атомарной записи.
     *
     * @param string $filePath
     *
     * @return string
     */
    private function createDirectoryAndTempFile(string $filePath): string
    {
        $directoryPath = dirname($filePath);

        if (!is_dir($directoryPath) && !mkdir($directoryPath, 0755, true) && !is_dir($directoryPath)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $directoryPath));
        }

        $tempPath = $filePath . '.tmp';

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        return $tempPath;
    }

    /**
     * Шифрует временный файл построчно и удаляет исходник.
     *
     * @param string $tempPath
     * @param string $encryptedPath
     */
    private function encryptTempFile(string $tempPath, string $encryptedPath): void
    {
        $readHandle = fopen($tempPath, 'rb');
        $writeHandle = fopen($encryptedPath, 'wb');

        if (!$readHandle || !$writeHandle) {
            throw new RuntimeException("Unable to open file handles for encryption: {$tempPath}");
        }

        try {
            $chunkSize = 5 * 1024 * 1024; // 5 MB
            while (!feof($readHandle)) {
                $chunk = fread($readHandle, $chunkSize);
                if ($chunk === false) {
                    throw new RuntimeException("Failed to read temp file: {$tempPath}");
                }

                if ($chunk === '') {
                    continue;
                }

                $encryptedChunk = Crypt::encryptString($chunk);
                $this->writeChunk($writeHandle, $encryptedChunk . PHP_EOL);
            }
        } finally {
            fclose($readHandle);
            fclose($writeHandle);
            unlink($tempPath);
        }
    }

    private static function iterateDecryptedChunks(string $filePath, int $chunkSize = 1048576): Generator
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Encrypted file not found: $filePath");
        }

        if (pathinfo($filePath, PATHINFO_EXTENSION) !== 'enc') {
            $handle = fopen($filePath, 'rb');

            if (!$handle) {
                throw new RuntimeException("Failed to open file: $filePath");
            }

            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, $chunkSize);

                    if ($chunk === false) {
                        throw new RuntimeException("Failed to read file: $filePath");
                    }

                    if ($chunk === '') {
                        continue;
                    }

                    yield $chunk;
                }
            } finally {
                fclose($handle);
            }

            return;
        }

        $handle = fopen($filePath, 'rb');

        if (!$handle) {
            throw new RuntimeException("Failed to open encrypted file: $filePath");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                yield Crypt::decryptString($line);
            }

            if (!feof($handle)) {
                throw new RuntimeException("Failed to read encrypted file: $filePath");
            }
        } finally {
            fclose($handle);
        }
    }

    private function skipWhitespace(string &$buffer): void
    {
        $buffer = ltrim($buffer);
    }

    private function consumeJsonString(string &$buffer, string $filePath): ?string
    {
        if ($buffer === '') {
            return null;
        }

        if ($buffer[0] !== '"') {
            throw new RuntimeException(sprintf('Invalid backup format in "%s": expected JSON string', $filePath));
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
                    throw new RuntimeException(
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
    private function consumeJsonValue(string &$buffer, string $filePath)
    {
        $inString = false;
        $escaped = false;
        $objectDepth = 0;
        $arrayDepth = 0;
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
                    return $this->decodeJsonToken(substr($buffer, 0, $i), $filePath, $buffer, $i);
                }

                $arrayDepth--;
                continue;
            }

            if ($char === ',' && $objectDepth === 0 && $arrayDepth === 0) {
                return $this->decodeJsonToken(substr($buffer, 0, $i), $filePath, $buffer, $i);
            }
        }

        return self::INCOMPLETE_JSON_VALUE;
    }

    /**
     * @return mixed
     */
    private function decodeJsonToken(string $token, string $filePath, string &$buffer, int $offset)
    {
        $decoded = json_decode($token, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                sprintf('Failed to decode JSON token from backup "%s": %s', $filePath, json_last_error_msg()),
            );
        }

        $buffer = substr($buffer, $offset);

        return $decoded;
    }
}
