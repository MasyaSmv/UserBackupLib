<?php

declare(strict_types=1);

namespace UserDataBackup\ValueObjects;

use InvalidArgumentException;

/**
 * Секция файла бэкапа: строки одной таблицы одного подключения.
 *
 * Подключение едет вместе с таблицей, а не угадывается по имени при восстановлении:
 * одноимённые таблицы разных подключений остаются различимы.
 */
final class BackupTableSection
{
    /**
     * @param iterable<int, iterable<int, mixed>> $sources Ленивые источники строк.
     */
    public function __construct(
        private string $connection,
        private string $table,
        private iterable $sources
    ) {
        if ($connection === '' || $table === '') {
            throw new InvalidArgumentException('Подключение и таблица секции бэкапа не могут быть пустыми.');
        }
    }

    public function connection(): string
    {
        return $this->connection;
    }

    public function table(): string
    {
        return $this->table;
    }

    /**
     * @return iterable<int, iterable<int, mixed>>
     */
    public function sources(): iterable
    {
        return $this->sources;
    }
}
