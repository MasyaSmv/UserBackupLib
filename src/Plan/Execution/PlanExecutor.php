<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Execution;

use UserDataBackup\Plan\Compiler\SelectorCompilerChain;
use UserDataBackup\Plan\CompiledUserDataPlan;
use UserDataBackup\Plan\Exceptions\UnhandledActionException;
use UserDataBackup\Plan\ScopeValues;
use UserDataBackup\Plan\UserDataRule;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

/**
 * Исполняет скомпилированный план: обходит правила в нужном порядке и применяет действия.
 *
 * Порядок берётся у плана, а не у порядка регистрации правил: дочерние строки обязаны
 * исчезнуть раньше родительских, иначе подзапрос перестанет их находить.
 */
final class PlanExecutor
{
    public const DEFAULT_CHUNK_SIZE = 1000;

    private ConnectionResolverInterface $connections;

    /**
     * @var array<int, TableActionHandler>
     */
    private array $handlers;

    private int $chunkSize;

    /**
     * @param array<int, TableActionHandler> $handlers
     */
    public function __construct(
        ConnectionResolverInterface $connections,
        array $handlers,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ) {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('Размер порции должен быть положительным.');
        }

        foreach ($handlers as $handler) {
            if (!$handler instanceof TableActionHandler) {
                throw new InvalidArgumentException('Исполнитель принимает только обработчики действий.');
            }
        }

        $this->connections = $connections;
        $this->handlers = array_values($handlers);
        $this->chunkSize = $chunkSize;
    }

    /**
     * Исполнитель со штатным набором обработчиков.
     */
    public static function default(
        ConnectionResolverInterface $connections,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ): self {
        $keyset = new KeysetCursor();
        $reader = new RowChunkReader(SelectorCompilerChain::default(), $keyset);

        return new self(
            $connections,
            [
                new DeleteRowsHandler($reader, $keyset),
                new DetachRowsHandler($reader, $keyset),
            ],
            $chunkSize,
        );
    }

    /**
     * @param bool $dryRun Только посчитать строки, ничего не меняя.
     *
     * @throws UnhandledActionException Изменяющему действию не нашёлся обработчик.
     */
    public function execute(
        CompiledUserDataPlan $plan,
        ScopeValues $scope,
        bool $dryRun = false
    ): ExecutionReport {
        $results = [];

        foreach ($this->assignHandlers($plan) as [$rule, $handler]) {
            $connectionName = $rule->tableRef()->connection();

            $rows = $handler->handle(
                $this->connections->connection($connectionName),
                $connectionName,
                $rule,
                $scope,
                $this->chunkSize,
                $dryRun,
            );

            $results[] = new TableExecutionResult(
                $rule->tableRef(),
                $rule->action()->value(),
                $rows,
            );
        }

        return new ExecutionReport($results, $dryRun, $plan->version());
    }

    /**
     * Обработчики для всего плана до первого изменения данных.
     *
     * `keep` и `backup_only` исполнителю не принадлежат и пропускаются. Изменяющее
     * действие без обработчика — ошибка: иначе операция рапортовала бы успех, оставив
     * строки на месте.
     *
     * @return array<int, array{0: UserDataRule, 1: TableActionHandler}>
     *
     * @throws UnhandledActionException
     */
    private function assignHandlers(CompiledUserDataPlan $plan): array
    {
        $assigned = [];
        $unhandled = [];

        foreach ($plan->inDeletionOrder() as $rule) {
            $handler = $this->handlerFor($rule);

            if ($handler !== null) {
                $assigned[] = [$rule, $handler];
            } elseif ($rule->action()->mutatesRows()) {
                $unhandled[$rule->tableRef()->key()] = $rule->action()->value();
            }
        }

        if ($unhandled !== []) {
            throw new UnhandledActionException($unhandled);
        }

        return $assigned;
    }

    private function handlerFor(UserDataRule $rule): ?TableActionHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($rule)) {
                return $handler;
            }
        }

        return null;
    }
}
