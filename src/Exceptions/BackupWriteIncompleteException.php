<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Порция бэкапа записалась в файл не целиком: `fwrite` вернул меньше байт или ничего (кончилось место).
 *
 * Без этой проверки недописанный файл считался готовым, загружался в хранилище и разрешал
 * удаление данных, у которых нет читаемой копии (WS-3133).
 */
final class BackupWriteIncompleteException extends FileStorageException
{
    public const CODE = 'user_backup.write_incomplete';

    public function __construct(
        private string $path,
        private int $expectedBytes,
        private int $writtenBytes
    ) {
        parent::__construct(sprintf(
            'Backup chunk written partially to "%s": %d of %d bytes',
            $path,
            $writtenBytes,
            $expectedBytes,
        ));
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * @return array<string, int|string>
     */
    public function context(): array
    {
        return [
            'error_code' => self::CODE,
            'path' => $this->path,
            'expected_bytes' => $this->expectedBytes,
            'written_bytes' => $this->writtenBytes,
        ];
    }
}
