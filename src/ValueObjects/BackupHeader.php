<?php

declare(strict_types=1);

namespace UserDataBackup\ValueObjects;

use UserDataBackup\Exceptions\BackupFormatException;
use InvalidArgumentException;

/**
 * Метаданные файла бэкапа: версии формата и плана, профиль, исходное окружение и
 * пользователь.
 *
 * Без них файл хранил только имена таблиц, и восстановление угадывало подключение по
 * имени, а tenant-ключ `{env}-{id}` собирало из текущего окружения — прод-снимок на
 * стенде получал чужой ключ. Файлы первой версии шапки не имеют: для них — `legacy()`.
 */
final class BackupHeader
{
    public const LEGACY_FORMAT = 1;

    public const CURRENT_FORMAT = 2;

    private function __construct(
        private int $formatVersion,
        private ?string $planVersion,
        private ?string $profile,
        private ?string $tenant,
        private ?int $userId,
        private ?string $createdAt
    ) {
    }

    public static function current(
        string $planVersion,
        string $profile,
        string $tenant,
        int $userId,
        string $createdAt
    ): self {
        foreach (['planVersion' => $planVersion, 'profile' => $profile, 'tenant' => $tenant] as $name => $value) {
            if ($value === '') {
                throw new InvalidArgumentException(sprintf('Поле шапки бэкапа "%s" не может быть пустым.', $name));
            }
        }

        return new self(self::CURRENT_FORMAT, $planVersion, $profile, $tenant, $userId, $createdAt);
    }

    /**
     * Файл без шапки: подключение и tenant в нём не записаны.
     */
    public static function legacy(): self
    {
        return new self(self::LEGACY_FORMAT, null, null, null, null, null);
    }

    /**
     * @param mixed $meta Декодированное значение `@meta` из файла.
     *
     * @throws BackupFormatException
     */
    public static function fromArray($meta, string $filePath): self
    {
        if (!is_array($meta)) {
            throw new BackupFormatException(sprintf('Invalid backup header in "%s": expected object', $filePath));
        }

        $version = $meta['format_version'] ?? null;

        if ($version !== self::CURRENT_FORMAT) {
            throw new BackupFormatException(sprintf(
                'Unsupported backup format version in "%s": %s',
                $filePath,
                json_encode($version),
            ));
        }

        return new self(
            self::CURRENT_FORMAT,
            self::stringOrNull($meta['plan_version'] ?? null),
            self::stringOrNull($meta['profile'] ?? null),
            self::stringOrNull($meta['tenant'] ?? null),
            isset($meta['user_id']) ? (int) $meta['user_id'] : null,
            self::stringOrNull($meta['created_at'] ?? null),
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'format_version' => $this->formatVersion,
            'plan_version' => $this->planVersion,
            'profile' => $this->profile,
            'tenant' => $this->tenant,
            'user_id' => $this->userId,
            'created_at' => $this->createdAt,
        ];
    }

    public function isLegacy(): bool
    {
        return $this->formatVersion === self::LEGACY_FORMAT;
    }

    public function formatVersion(): int
    {
        return $this->formatVersion;
    }

    public function planVersion(): ?string
    {
        return $this->planVersion;
    }

    public function profile(): ?string
    {
        return $this->profile;
    }

    public function tenant(): ?string
    {
        return $this->tenant;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function createdAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * @param mixed $value
     */
    private static function stringOrNull($value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
