<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FileStorageServiceInterface;
use App\Exceptions\BackupEncryptionException;
use App\Exceptions\BackupSerializationException;
use App\Exceptions\FileStorageException;
use App\Services\Internal\BackupChunkReader;
use App\Services\Internal\BackupStreamEntry;
use App\Services\Internal\FileSystemAdapter;
use App\Services\Internal\BackupJsonStreamParser;
use Generator;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Потоковая запись/чтение бэкапов с опциональным шифрованием чанками.
 */
class FileStorageService implements FileStorageServiceInterface
{
    public function __construct(
        private ?FileSystemAdapter $fileSystem = null
    ) {
    }

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
        $tempHandle = $this->fileSystem()->openForWrite($tempPath);

        try {
            $this->writeJsonStream($tempHandle, $data);
        } finally {
            $this->fileSystem()->close($tempHandle);
        }

        if ($encrypt) {
            $encryptedPath = $filePath . '.enc';
            $this->encryptTempFile($tempPath, $encryptedPath);

            return $encryptedPath;
        }

        $this->fileSystem()->rename($tempPath, $filePath);

        return $filePath;
    }

    public function streamBackupData(string $filePath): Generator
    {
        foreach ($this->createParser()->parse(
            $this->createChunkReader()->iterateDecryptedChunks($filePath),
            $filePath,
        ) as $entry) {
            /** @var BackupStreamEntry $entry */
            yield $entry->toArray();
        }
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
                    throw new BackupSerializationException('Failed to encode backup row to JSON: ' . json_last_error_msg());
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
        $this->fileSystem()->write($handle, $chunk);
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

        $this->fileSystem()->ensureDirectory($directoryPath);

        return $this->createUniqueTempPath($filePath);
    }

    /**
     * Шифрует временный файл построчно и удаляет исходник.
     *
     * @param string $tempPath
     * @param string $encryptedPath
     */
    private function encryptTempFile(string $tempPath, string $encryptedPath): void
    {
        $readHandle = $this->fileSystem()->openForRead($tempPath);
        $writeHandle = $this->fileSystem()->openForWrite($encryptedPath);

        try {
            $chunkSize = 5 * 1024 * 1024; // 5 MB
            while (!feof($readHandle)) {
                $chunk = $this->fileSystem()->readChunk($readHandle, $chunkSize, $tempPath, 'Failed to read temp file: %s');

                if ($chunk === '') {
                    break;
                }

                $encryptedChunk = $this->encryptChunk($chunk, $encryptedPath);
                $this->writeChunk($writeHandle, $encryptedChunk . PHP_EOL);
            }
        } finally {
            $this->fileSystem()->close($readHandle);
            $this->fileSystem()->close($writeHandle);
            $this->fileSystem()->delete($tempPath);
        }
    }

    protected function createChunkReader(): BackupChunkReader
    {
        return new BackupChunkReader($this->fileSystem());
    }

    protected function createParser(): BackupJsonStreamParser
    {
        return new BackupJsonStreamParser();
    }

    protected function encryptChunk(string $chunk, string $encryptedPath): string
    {
        try {
            return Crypt::encryptString($chunk);
        } catch (Throwable $exception) {
            throw new BackupEncryptionException(
                sprintf('Failed to encrypt backup chunk for "%s"', $encryptedPath),
                previous: $exception,
            );
        }
    }

    protected function createTempSuffix(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function createUniqueTempPath(string $filePath): string
    {
        $maxAttempts = 10;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $tempPath = sprintf('%s.%s.tmp', $filePath, $this->createTempSuffix());

            if (!$this->fileSystem()->exists($tempPath)) {
                return $tempPath;
            }
        }

        throw new FileStorageException(sprintf('Unable to allocate unique temp file for "%s"', $filePath));
    }

    protected function fileSystem(): FileSystemAdapter
    {
        return $this->fileSystem ??= new FileSystemAdapter();
    }
}
