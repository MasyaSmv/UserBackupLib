<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FileStorageServiceInterface;
use App\Exceptions\BackupEncryptionException;
use App\Exceptions\FileStorageException;
use App\Services\Internal\BackupChunkReader;
use App\Services\Internal\BackupFormatDetector;
use App\Services\Internal\BackupRowIterator;
use App\Services\Internal\BackupStreamEntry;
use App\Services\Internal\FileSystemAdapter;
use App\Services\Internal\BackupJsonStreamParser;
use App\Services\Internal\BackupV2JsonStreamParser;
use App\Services\Internal\BackupV2JsonWriter;
use App\ValueObjects\BackupHeader;
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

    /**
     * Файл первой версии: без шапки и подключений. Пишется только для совместимости и тестов;
     * бэкапы по плану пишутся через `saveBackup`.
     */
    public function saveToFile(string $filePath, iterable $data, bool $encrypt = true): string
    {
        return $this->writeAtomically($filePath, $encrypt, function ($handle) use ($data): void {
            $this->writeLegacyJsonStream($handle, $data);
        });
    }

    public function saveBackup(string $filePath, BackupHeader $header, iterable $sections, bool $encrypt = true): string
    {
        return $this->writeAtomically($filePath, $encrypt, function ($handle) use ($header, $sections): void {
            $this->createV2Writer()->write($handle, $header, $sections);
        });
    }

    public function streamBackupData(string $filePath): Generator
    {
        [$format, $chunks] = $this->detectFormat($filePath);

        $parser = $format === BackupHeader::CURRENT_FORMAT ? $this->createV2Parser() : $this->createParser();

        foreach ($parser->parse($chunks, $filePath) as $entry) {
            /** @var BackupStreamEntry $entry */
            yield $entry->toArray();
        }
    }

    public function readHeader(string $filePath): BackupHeader
    {
        [$format, $chunks] = $this->detectFormat($filePath);

        if ($format !== BackupHeader::CURRENT_FORMAT) {
            return BackupHeader::legacy();
        }

        return $this->createV2Parser()->header($chunks, $filePath);
    }

    /**
     * @return array{0: int, 1: Generator<int, string>}
     */
    private function detectFormat(string $filePath): array
    {
        return (new BackupFormatDetector())->detect($this->createChunkReader()->iterateDecryptedChunks($filePath));
    }

    /**
     * Пишет во временный файл и только потом переименовывает или шифрует: недописанный
     * бэкап не должен выглядеть как готовый.
     *
     * @param callable(resource): void $writer
     */
    private function writeAtomically(string $filePath, bool $encrypt, callable $writer): string
    {
        $tempPath = $this->createDirectoryAndTempFile($filePath);
        $this->writeFile($tempPath, $writer);

        if ($encrypt) {
            $encryptedPath = $filePath . '.enc';
            $this->encryptTempFile($tempPath, $encryptedPath);

            return $encryptedPath;
        }

        $this->fileSystem()->rename($tempPath, $filePath);

        return $filePath;
    }

    /**
     * Файл считается записанным только после успешного закрытия; при любом сбое недописанный
     * файл удаляется, чтобы его нельзя было принять за готовый (WS-3133).
     *
     * @param callable(resource): void $writer
     */
    private function writeFile(string $path, callable $writer): void
    {
        $handle = $this->fileSystem()->openForWrite($path);

        try {
            $writer($handle);
        } catch (Throwable $e) {
            $this->fileSystem()->close($handle);
            $this->fileSystem()->delete($path);

            throw $e;
        }

        try {
            $this->fileSystem()->closeWritten($handle);
        } catch (Throwable $e) {
            $this->fileSystem()->delete($path);

            throw $e;
        }
    }

    /**
     * @param resource $handle
     * @param iterable<string, iterable> $data
     */
    private function writeLegacyJsonStream($handle, iterable $data): void
    {
        $this->writeChunk($handle, '{');

        $isFirstTable = true;

        foreach ($data as $table => $tableChunks) {
            $tableStarted = false;

            foreach ((new BackupRowIterator())->encodedRows($tableChunks) as $encodedRow) {
                // Заголовок секции пишем ЛЕНИВО — только при первой строке. Таблицы без
                // строк вообще не попадают в файл: восстанавливать в них нечего, а мы
                // экономим на записи и на разборе при restore.
                if (!$tableStarted) {
                    $this->writeChunk($handle, ($isFirstTable ? '' : ',') . json_encode((string) $table, JSON_UNESCAPED_UNICODE) . ':[');
                    $tableStarted = true;
                } else {
                    $this->writeChunk($handle, ',');
                }

                $this->writeChunk($handle, $encodedRow);
            }

            if ($tableStarted) {
                $this->writeChunk($handle, ']');
                $isFirstTable = false;
            }
        }

        $this->writeChunk($handle, '}');
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
        // Шифротекст тоже пишется во временный файл: иначе сбой посередине оставлял бы
        // недописанный `.enc` под именем готового бэкапа (WS-3133).
        $encryptedTempPath = $this->createUniqueTempPath($encryptedPath);
        $readHandle = $this->fileSystem()->openForRead($tempPath);

        try {
            $this->writeFile($encryptedTempPath, function ($writeHandle) use ($readHandle, $tempPath, $encryptedPath): void {
                $this->encryptStream($readHandle, $writeHandle, $tempPath, $encryptedPath);
            });
        } finally {
            $this->fileSystem()->close($readHandle);
            $this->fileSystem()->delete($tempPath);
        }

        $this->fileSystem()->rename($encryptedTempPath, $encryptedPath);
    }

    /**
     * @param resource $readHandle
     * @param resource $writeHandle
     */
    private function encryptStream($readHandle, $writeHandle, string $tempPath, string $encryptedPath): void
    {
        $chunkSize = 5 * 1024 * 1024; // 5 MB

        while (!feof($readHandle)) {
            $chunk = $this->fileSystem()->readChunk($readHandle, $chunkSize, $tempPath, 'Failed to read temp file: %s');

            if ($chunk === '') {
                break;
            }

            $this->writeChunk($writeHandle, $this->encryptChunk($chunk, $encryptedPath) . PHP_EOL);
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

    protected function createV2Parser(): BackupV2JsonStreamParser
    {
        return new BackupV2JsonStreamParser();
    }

    protected function createV2Writer(): BackupV2JsonWriter
    {
        return new BackupV2JsonWriter($this->fileSystem(), new BackupRowIterator());
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
