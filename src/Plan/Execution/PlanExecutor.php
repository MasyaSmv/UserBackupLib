<?php

declare(strict_types=1);

namespace App\Plan\Execution;

use App\Plan\Compiler\SelectorCompilerChain;
use App\Plan\CompiledUserDataPlan;
use App\Plan\ScopeValues;
use App\Plan\UserDataRule;
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
        $reader = new RowChunkReader(SelectorCompilerChain::default());

        return new self(
            $connections,
            [
                new DeleteRowsHandler($reader),
                new DetachRowsHandler($reader),
            ],
            $chunkSize,
        );
    }

    /**
     * @param bool $dryRun Только посчитать строки, ничего не меняя.
     */
    public function execute(
        CompiledUserDataPlan $plan,
        ScopeValues $scope,
        bool $dryRun = false
    ): ExecutionReport {
        $results = [];

        foreach ($plan->inDeletionOrder() as $rule) {
            $handler = $this->handlerFor($rule);

            if ($handler === null) {
                continue;
            }

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
