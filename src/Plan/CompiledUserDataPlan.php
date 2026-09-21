<?php

declare(strict_types=1);

namespace App\Plan;

use App\Plan\Exceptions\PlanIncompleteException;
use InvalidArgumentException;

/**
 * Готовый к исполнению план: правило на каждую таблицу плюс порядок обработки.
 *
 * План неизменяем и собирается компилятором один раз за операцию. Его версия
 * записывается в backup, чтобы при восстановлении было видно, по какому описанию схемы
 * снимали данные.
 */
final class CompiledUserDataPlan
{
    /**
     * @var array<string, UserDataRule>
     */
    private array $rules;

    /**
     * @var array<int, UserDataRule>|null Кэш отсортированного порядка.
     */
    private ?array $ordered = null;

    /**
     * @param array<int, UserDataRule> $rules
     */
    public function __construct(array $rules)
    {
        $indexed = [];

        foreach ($rules as $rule) {
            if (!$rule instanceof UserDataRule) {
                throw new InvalidArgumentException('План принимает только правила UserDataRule.');
            }

            $key = $rule->tableRef()->key();

            if (isset($indexed[$key])) {
                throw new InvalidArgumentException('Дублирующее правило для таблицы ' . $key . '.');
            }

            $indexed[$key] = $rule;
        }

        $this->rules = $indexed;
    }

    public function has(TableRef $tableRef): bool
    {
        return isset($this->rules[$tableRef->key()]);
    }

    public function ruleFor(TableRef $tableRef): ?UserDataRule
    {
        return $this->rules[$tableRef->key()] ?? null;
    }

    /**
     * @return array<string, UserDataRule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Правила в порядке обработки: дочерние таблицы раньше родительских.
     *
     * @return array<int, UserDataRule>
     */
    public function inDeletionOrder(): array
    {
        if ($this->ordered === null) {
            $this->ordered = (new DeletionOrder())->sort($this->rules);
        }

        return $this->ordered;
    }

    /**
     * Правила, строки которых нужно читать: keep в backup не попадает.
     *
     * @return array<int, UserDataRule>
     */
    public function readable(): array
    {
        $readable = [];

        foreach ($this->inDeletionOrder() as $rule) {
            if ($rule->action()->readsRows()) {
                $readable[] = $rule;
            }
        }

        return $readable;
    }

    /**
     * Проверяет, что план покрывает все таблицы схемы.
     *
     * @param array<int, TableRef> $tablesInSchema
     *
     * @throws PlanIncompleteException
     */
    public function assertCovers(array $tablesInSchema): void
    {
        $missing = [];

        foreach ($tablesInSchema as $tableRef) {
            if (!$this->has($tableRef)) {
                $missing[] = $tableRef->key();
            }
        }

        if ($missing !== []) {
            throw new PlanIncompleteException($missing);
        }
    }

    /**
     * Отпечаток состава плана. Пишется в метаданные backup рядом с версией формата.
     */
    public function version(): string
    {
        $parts = [];

        foreach ($this->rules as $key => $rule) {
            $parts[] = $key . ':' . $rule->action()->value();
        }

        sort($parts);

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }
}
