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
        $this->preflight->check($plan, $connectionNames);

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
        $this->preflight->check($plan, $connectionNames);

        return $this->executor->execute($plan, $scope, true);
    }
}
