<?php

declare(strict_types=1);

namespace UserDataBackup\Exceptions;

/**
 * Сжатый поток бэкапа не распаковался: данные повреждены или файл оборван до конца
 * сжатого потока. Восстанавливать из такого файла нельзя: часть строк потеряна.
 */
final class BackupDecompressionException extends BackupException
{
    public const CODE = 'user_backup.decompression_failed';

    public const STAGE_INIT = 'init';
    public const STAGE_INFLATE = 'inflate';
    public const STAGE_TRUNCATED = 'truncated';

    public function __construct(
        private string $stage,
        private int $compressedBytes
    ) {
        parent::__construct(sprintf(
            'Failed to decompress backup stream at stage "%s" after %d compressed bytes',
            $stage,
            $compressedBytes,
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
            'stage' => $this->stage,
            'compressed_bytes' => $this->compressedBytes,
        ];
    }
}
