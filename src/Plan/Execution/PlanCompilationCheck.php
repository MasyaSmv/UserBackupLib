<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Execution;

use UserDataBackup\Plan\CompiledUserDataPlan;
use UserDataBackup\Plan\Compiler\SelectorCompiler;
use UserDataBackup\Plan\ScopeValues;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Проверяет, что каждое правило плана компилируется в запрос, не выполняя ни одного.
 *
 * Ошибки описания — неподдерживаемый селектор, родитель на другом подключении,
 * отсутствующий ключ скоупа — бросаются на этапе компиляции. Раньше их ловил только
 * сухой прогон, а он запускался лишь при заданных лимитах: без лимитов ошибка всплывала
 * посреди удаления (WS-3105). Проверка дешёвая, поэтому идёт на каждом запуске, а
 * подсчёт строк остаётся за сухим прогоном.
 */
final class PlanCompilationCheck
{
    private ConnectionResolverInterface $connections;

    private SelectorCompiler $compiler;

    public function __construct(ConnectionResolverInterface $connections, SelectorCompiler $compiler)
    {
        $this->connections = $connections;
        $this->compiler = $compiler;
    }

    /**
     * @throws \UserDataBackup\Plan\Exceptions\PlanException Правило не компилируется в запрос.
     */
    public function assertCompiles(CompiledUserDataPlan $plan, ScopeValues $scope): void
    {
        foreach ($plan->rules() as $rule) {
            $selector = $rule->selector();

            if ($selector === null) {
                continue;
            }

            $connectionName = $rule->tableRef()->connection();
            $query = $this->connections->connection($connectionName)->table($rule->tableRef()->table());

            $this->compiler->apply($query, $selector, $scope, $connectionName, $this->compiler);
        }
    }
}
