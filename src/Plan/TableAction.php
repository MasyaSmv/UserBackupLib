<?php

declare(strict_types=1);

namespace UserDataBackup\Plan;

use InvalidArgumentException;

/**
 * Что профиль делает со строками таблицы, принадлежащими пользователю.
 *
 * Отделено от селектора намеренно: «чьи это строки» и «что с ними делать» — разные
 * вопросы. Одна и та же принадлежность в профиле сброса портфеля и в профиле полного
 * удаления приводит к разным действиям.
 *
 * PHP 8.0 — enum недоступен, поэтому набор задан константами с приватным конструктором.
 */
final class TableAction
{
    /** Строки попадают в backup и удаляются. */
    public const BACKUP_AND_DELETE = 'backup_and_delete';

    /** Строки не читаются и не удаляются. */
    public const KEEP = 'keep';

    /**
     * Строки попадают в backup, но не удаляются.
     *
     * Нужно для строк, которые восстановление обязано вернуть к состоянию снимка, но
     * удалять которые нельзя: сама строка `users` при возврате из бэкапа обновляется
     * upsert-ом, а её удаление оставило бы пользователя без учётной записи в промежутке.
     */
    public const BACKUP_ONLY = 'backup_only';

    /** Строки остаются, но теряют персональные значения. */
    public const ANONYMIZE = 'anonymize';

    /** Остаётся строка, обнуляется только ссылка на удаляемого пользователя. */
    public const DETACH = 'detach';

    private const ALL = [
        self::BACKUP_AND_DELETE,
        self::KEEP,
        self::BACKUP_ONLY,
        self::ANONYMIZE,
        self::DETACH,
    ];

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException('Неизвестное действие над таблицей: ' . $value);
        }

        $this->value = $value;
    }

    public static function backupAndDelete(): self
    {
        return new self(self::BACKUP_AND_DELETE);
    }

    public static function keep(): self
    {
        return new self(self::KEEP);
    }

    public static function backupOnly(): self
    {
        return new self(self::BACKUP_ONLY);
    }

    public static function anonymize(): self
    {
        return new self(self::ANONYMIZE);
    }

    public static function detach(): self
    {
        return new self(self::DETACH);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isKeep(): bool
    {
        return $this->value === self::KEEP;
    }

    /**
     * Читает ли действие строки таблицы. Keep не читает — такие строки не нужны и в backup.
     */
    public function readsRows(): bool
    {
        return $this->value !== self::KEEP;
    }

    /**
     * Действие меняет данные в таблице: у такого действия обязан быть обработчик.
     *
     * `backup_only` строки читает, но не меняет: его обрабатывает выгрузка, а не исполнитель.
     */
    public function mutatesRows(): bool
    {
        return $this->readsRows() && $this->value !== self::BACKUP_ONLY;
    }

    public function deletesRows(): bool
    {
        return $this->value === self::BACKUP_AND_DELETE;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
