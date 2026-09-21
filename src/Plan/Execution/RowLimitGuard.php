<?php

declare(strict_types=1);

namespace App\Plan\Execution;

use App\Plan\Exceptions\RowLimitExceededException;

/**
 * Проверяет охват сухого прогона против предохранителя.
 */
final class RowLimitGuard
{
    /**
     * @throws RowLimitExceededException
     */
    public function check(ExecutionReport $report, RowLimits $limits): void
    {
        $perTable = $limits->perTable();

        if ($perTable !== null) {
            foreach ($report->results() as $result) {
                if ($result->rows() > $perTable) {
                    throw new RowLimitExceededException(
                        $result->tableRef()->key(),
                        $result->rows(),
                        $perTable,
                    );
                }
            }
        }

        $total = $limits->total();

        if ($total !== null && $report->totalRows() > $total) {
            throw new RowLimitExceededException('total', $report->totalRows(), $total);
        }
    }
}
