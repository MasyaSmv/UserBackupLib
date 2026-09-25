<?php

declare(strict_types=1);

namespace UserDataBackup\Exceptions;

/**
 * Записанный файл бэкапа не сбросился на диск или не закрылся: буфер мог не дойти до файла (WS-3133).
 */
final class BackupFileCloseException extends FileStorageException
{
    public const CODE = 'user_backup.file_close_failed';

    public function __construct(private string $path)
    {
        parent::__construct(sprintf('Backup file "%s" was not flushed or closed', $path));
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'error_code' => self::CODE,
            'path' => $this->path,
        ];
    }
}
