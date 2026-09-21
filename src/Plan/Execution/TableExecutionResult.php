<?php

declare(strict_types=1);

namespace App\Plan\Execution;

use App\Plan\TableRef;

/**
 * Что произошло с одной таблицей во время исполнения плана.
 */
final class TableExecutionResult
{
    private TableRef $tableRef;

    private string $action;

    private int $rows;

    public function __construct(TableRef $tableRef, string $action, int $rows)
    {
        $this->tableRef = $tableRef;
        $this->action = $action;
        $this->rows = $rows;
    }

    public function tableRef(): TableRef
    {
        return $this->tableRef;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->tableRef->connection(),
            'table' => $this->tableRef->table(),
            'action' => $this->action,
            'rows' => $this->rows,
        ];
    }
}
