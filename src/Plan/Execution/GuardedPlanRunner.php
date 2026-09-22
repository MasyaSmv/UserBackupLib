<?php

declare(strict_types=1);

namespace App\Plan\Execution;

use App\Plan\CompiledUserDataPlan;
use App\Plan\Preflight\PlanPreflight;
use App\Plan\ScopeValues;

/**
 * Безопасный порядок исполнения плана: сверка со схемой, сухой прогон, предохранитель,
 * и только потом изменение данных.
 *
 * Сухой прогон стоит лишнего прохода по таблицам, и это осознанная плата: ошибка в
 * правиле не выглядит как падение — операция завершается успешно, просто уносит лишнее.
 * Единственный момент, когда её ещё можно заметить, — до первого удаления.
 */
final class GuardedPlanRunner
{
    private PlanPreflight $preflight;

    private PlanExecutor $executor;

    private RowLimitGuard $guard;

    public function __construct(
        PlanPreflight $preflight,
        PlanExecutor $executor,
        RowLimitGuard $guard
    ) {
        $this->preflight = $preflight;
        $this->executor = $executor;
        $this->guard = $guard;
    }

    /**
     * @param array<int, string> $connectionNames Подключения, которые обслуживает план.
     *
     * @throws \App\Plan\Exceptions\PlanException
     */
    public function run(
        CompiledUserDataPlan $plan,
        ScopeValues $scope,
        array $connectionNames,
        RowLimits $limits
    ): ExecutionReport {
        $plan = $this->planForSchema($plan, $connectionNames);

        if ($limits->hasAny()) {
            $this->guard->check(
                $this->executor->execute($plan, $scope, true),
                $limits,
            );
        }

        return $this->executor->execute($plan, $scope);
    }

    /**
     * Только сверка и подсчёт, без изменения данных.
     *
     * @param array<int, string> $connectionNames
     */
    public function preview(
        CompiledUserDataPlan $plan,
        ScopeValues $scope,
        array $connectionNames
    ): ExecutionReport {
        return $this->executor->execute($this->planForSchema($plan, $connectionNames), $scope, true);
    }

    /**
     * План, урезанный до таблиц, которые в этой схеме действительно есть.
     *
     * Набор таблиц отличается между окружениями, и preflight считает это предупреждением:
     * удалять там всё равно нечего. Без этого шага исполнитель всё равно шёл бы в
     * отсутствующую таблицу и ронял операцию на первом же запросе (WS-3069).
     *
     * @param array<int, string> $connectionNames
     *
     * @throws \App\Plan\Exceptions\PlanException
     */
    private function planForSchema(CompiledUserDataPlan $plan, array $connectionNames): CompiledUserDataPlan
    {
        $report = $this->preflight->check($plan, $connectionNames);

        return $plan->withoutTables($report->tablesMissingInSchema());
    }
}
