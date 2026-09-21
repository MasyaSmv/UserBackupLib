<?php

declare(strict_types=1);

namespace App\Plan;

use InvalidArgumentException;

/**
 * Адрес таблицы: подключение плюс имя. Ключ, по которому план находит правило.
 *
 * Имя таблицы само по себе адресом не является: одно и то же имя существует в разных
 * подключениях (`jobs`, `failed_jobs`, `migrations` есть и в mysql, и в catalog), а при
 * восстановлении строку нужно вернуть именно в ту схему, откуда её взяли.
 */
final class TableRef
{
    private string $connection;

    private string $table;

    public function __construct(string $connection, string $table)
    {
        if ($connection === '') {
            throw new InvalidArgumentException('Имя подключения не может быть пустым.');
        }

        if ($table === '') {
            throw new InvalidArgumentException('Имя таблицы не может быть пустым.');
        }

        $this->connection = $connection;
        $this->table = $table;
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
     * Строковый ключ для индексации правил и сообщений об ошибках.
     */
    public function key(): string
    {
        return $this->connection . '.' . $this->table;
    }

    public function equals(self $other): bool
    {
        return $this->connection === $other->connection && $this->table === $other->table;
    }

    public function __toString(): string
    {
        return $this->key();
    }
}
