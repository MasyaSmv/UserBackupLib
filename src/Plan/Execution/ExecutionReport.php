<?php

declare(strict_types=1);

namespace App\Plan\Execution;

/**
 * Итог исполнения плана: сколько строк и в каких таблицах затронуто.
 *
 * Отчёт строится одинаково в сухом прогоне и в боевом, поэтому расхождение между ними
 * само по себе является сигналом: значит, между проверкой и выполнением данные изменились.
 */
final class ExecutionReport
{
    /**
     * @var array<int, TableExecutionResult>
     */
    private array $results;

    private bool $dryRun;

    private string $planVersion;

    /**
     * @param array<int, TableExecutionResult> $results
     */
    public function __construct(array $results, bool $dryRun, string $planVersion)
    {
        $this->results = array_values($results);
        $this->dryRun = $dryRun;
        $this->planVersion = $planVersion;
    }

    /**
     * @return array<int, TableExecutionResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function planVersion(): string
    {
        return $this->planVersion;
    }

    public function totalRows(): int
    {
        $total = 0;

        foreach ($this->results as $result) {
            $total += $result->rows();
        }

        return $total;
    }

    /**
     * Только таблицы, которых операция действительно коснулась.
     *
     * @return array<int, TableExecutionResult>
     */
    public function touched(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (TableExecutionResult $result): bool => $result->rows() > 0,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'plan_version' => $this->planVersion,
            'total_rows' => $this->totalRows(),
            'tables' => array_map(
                static fn (TableExecutionResult $result): array => $result->toArray(),
                $this->touched(),
            ),
        ];
    }
}
