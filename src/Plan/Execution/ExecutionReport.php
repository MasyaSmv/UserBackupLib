<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Execution;

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
     * @var array<int, string> Таблицы плана, которых нет в схеме этого окружения.
     */
    private array $skippedTables = [];

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

    /**
     * Копия отчёта с таблицами, пропущенными из-за отсутствия в схеме.
     *
     * Без этого отчёт об успешной операции не отличался бы от отчёта об операции, из
     * которой выпала часть плана (WS-3105).
     *
     * @param array<int, string> $tableKeys
     */
    public function withSkippedTables(array $tableKeys): self
    {
        $copy = clone $this;
        $copy->skippedTables = array_values($tableKeys);

        return $copy;
    }

    /**
     * @return array<int, string>
     */
    public function skippedTables(): array
    {
        return $this->skippedTables;
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
            'skipped_tables' => $this->skippedTables,
            'tables' => array_map(
                static fn (TableExecutionResult $result): array => $result->toArray(),
                $this->touched(),
            ),
        ];
    }
}
