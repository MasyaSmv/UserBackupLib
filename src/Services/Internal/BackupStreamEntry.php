<?php

declare(strict_types=1);

namespace App\Services\Internal;

final class BackupStreamEntry
{
    /**
     * @param mixed $row
     */
    public function __construct(
        private string $table,
        private $row
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

    /**
     * @return array{table: string, row: mixed}
     */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'row' => $this->row,
        ];
    }
}
