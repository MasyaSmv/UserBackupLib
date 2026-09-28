<?php

declare(strict_types=1);

namespace UserDataBackup\Exceptions;

/**
 * zlib не смог сжать поток бэкапа. Файл не дописан, и считать его готовым нельзя.
 */
final class BackupCompressionException extends BackupException
{
    public const CODE = 'user_backup.compression_failed';

    public const STAGE_INIT = 'init';
    public const STAGE_DEFLATE = 'deflate';

    public function __construct(
        private string $stage
    ) {
        parent::__construct(sprintf('Failed to compress backup stream at stage "%s"', $stage));
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
            'stage' => $this->stage,
        ];
    }
}
