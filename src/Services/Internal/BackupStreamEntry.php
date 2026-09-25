<?php

declare(strict_types=1);

namespace UserDataBackup\Services\Internal;

final class BackupStreamEntry
{
    /**
     * @param mixed $row
     * @param string|null $connection Подключение из файла; `null` у файлов без шапки.
     */
    public function __construct(
        private string $table,
        private $row,
        private ?string $connection = null
    ) {
    }

    public function table(): string
    {
        return $this->table;
    }

    /**
     * @return mixed
     */
    public function row()
    {
        return $this->row;
    }

    public function connection(): ?string
    {
        return $this->connection;
    }

    /**
     * @return array{table: string, row: mixed, connection: string|null}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'row' => $this->row,
            'connection' => $this->connection,
        ];
    }
}
