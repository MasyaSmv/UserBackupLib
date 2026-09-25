<?php

declare(strict_types=1);

namespace UserDataBackup\Plan;

use UserDataBackup\Plan\Exceptions\PlanIncompleteException;
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
     * @var string|null Версия исходного плана у его урезанной копии.
     */
    private ?string $sourceVersion = null;

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
     * Копия плана без перечисленных таблиц.
     *
     * Нужна для таблиц, которых нет в схеме окружения: preflight считает их
     * предупреждением, а не ошибкой, но исполнитель без этой фильтрации всё равно шёл бы
     * в несуществующую таблицу и ронял операцию.
     *
     * Версия остаётся версией исходного плана: это отпечаток описания, а не схемы
     * конкретного стенда, иначе один и тот же план получал бы разные версии в разных
     * окружениях и бэкапы перестали бы сопоставляться.
     *
     * @param array<int, string> $tableKeys Ключи вида `connection.table`.
     */
    public function withoutTables(array $tableKeys): self
    {
        if ($tableKeys === []) {
            return $this;
        }

        $excluded = array_flip($tableKeys);
        $kept = [];

        foreach ($this->rules as $key => $rule) {
            if (!isset($excluded[$key])) {
                $kept[] = $rule;
            }
        }

        $copy = new self($kept);
        $copy->sourceVersion = $this->version();

        return $copy;
    }

    /**
     * Отпечаток состава плана. Пишется в метаданные backup рядом с версией формата.
     */
    public function version(): string
    {
        if ($this->sourceVersion !== null) {
            return $this->sourceVersion;
        }

        $parts = [];

        foreach ($this->rules as $key => $rule) {
            $parts[] = $key . ':' . $rule->action()->value();
        }

        sort($parts);

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }
}
