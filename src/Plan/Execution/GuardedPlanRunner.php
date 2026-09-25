<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Execution;

use UserDataBackup\Plan\CompiledUserDataPlan;
use UserDataBackup\Plan\Preflight\PlanPreflight;
use UserDataBackup\Plan\Preflight\PreflightReport;
use UserDataBackup\Plan\ScopeValues;

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

    private PlanCompilationCheck $compilation;

    private PlanExecutor $executor;

    private RowLimitGuard $guard;

    public function __construct(
        PlanPreflight $preflight,
        PlanCompilationCheck $compilation,
        PlanExecutor $executor,
        RowLimitGuard $guard
    ) {
        $this->preflight = $preflight;
        $this->compilation = $compilation;
        $this->executor = $executor;
        $this->guard = $guard;
    }

    /**
     * @param array<int, string> $connectionNames Подключения, которые обслуживает план.
     *
     * @throws \UserDataBackup\Plan\Exceptions\PlanException
     */
    public function run(
        CompiledUserDataPlan $plan,
        ScopeValues $scope,
        array $connectionNames,
        RowLimits $limits
    ): ExecutionReport {
        $preflight = $this->preflight->check($plan, $connectionNames);
        $plan = $this->executablePlan($plan, $preflight, $scope);

        if ($limits->hasAny()) {
            $this->guard->check(
                $this->executor->execute($plan, $scope, true),
                $limits,
            );
        }

        return $this->executor
            ->execute($plan, $scope)
            ->withSkippedTables($preflight->tablesMissingInSchema());
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
        $preflight = $this->preflight->check($plan, $connectionNames);

        return $this->executor
            ->execute($this->executablePlan($plan, $preflight, $scope), $scope, true)
            ->withSkippedTables($preflight->tablesMissingInSchema());
    }

    /**
     * План, урезанный до таблиц, которые в этой схеме действительно есть, и проверенный
     * на исполнимость.
     *
     * Набор таблиц отличается между окружениями, и preflight считает это предупреждением:
     * удалять там всё равно нечего. Без урезания исполнитель шёл бы в отсутствующую
     * таблицу и ронял операцию на первом же запросе (WS-3069). Пропущенные таблицы
     * попадают в отчёт, чтобы урезание не было молчаливым.
     *
     * Компиляция проверяется всегда, а не только перед сухим прогоном: без лимитов ошибка
     * описания иначе всплыла бы посреди удаления (WS-3105).
     *
     * @throws \UserDataBackup\Plan\Exceptions\PlanException
     */
    private function executablePlan(
        CompiledUserDataPlan $plan,
        PreflightReport $preflight,
        ScopeValues $scope
    ): CompiledUserDataPlan {
        $plan = $plan->withoutTables($preflight->tablesMissingInSchema());

        $this->compilation->assertCompiles($plan, $scope);

        return $plan;
    }
}
