<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FileStorageServiceInterface;
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
        if (!file_exists($encryptedFilePath)) {
            throw new RuntimeException("Encrypted file not found: $encryptedFilePath");
        }

        $rawContent = file_get_contents($encryptedFilePath);

        if ($rawContent === false) {
            throw new RuntimeException("Failed to read file: $encryptedFilePath");
        }

        if (pathinfo($encryptedFilePath, PATHINFO_EXTENSION) === 'enc') {
            if (!str_contains($rawContent, "\n") && !str_contains($rawContent, "\r")) {
                $jsonData = Crypt::decryptString($rawContent);
            } else {
                $lines = preg_split('/\r\n|\n|\r/', trim($rawContent));
                $jsonBuffer = '';

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $jsonBuffer .= Crypt::decryptString($line);
                }

                $jsonData = $jsonBuffer;
            }
        } else {
            $jsonData = $rawContent;
        }

        $decoded = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                sprintf(
                    'Failed to decode JSON from backup file "%s": %s',
                    $encryptedFilePath,
                    json_last_error_msg(),
                ),
            );
        }

        return $decoded;
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
}
