<?php

declare(strict_types=1);

namespace App\Plan;

use App\Plan\Selector\Selector;
use InvalidArgumentException;

/**
 * Правило для одной таблицы: чьи это строки и что с ними делать.
 *
 * Правило самодостаточно и не зависит от профиля: профиль выбирает, какие правила
 * применять и с каким действием, но саму связь с пользователем описывает одно место.
 */
final class UserDataRule
{
    private TableRef $tableRef;

    private TableAction $action;

    private ?Selector $selector;

    private string $primaryKey;

    /**
     * @var array<int, string> Колонки, обнуляемые действием detach.
     */
    private array $detachColumns;

    /**
     * @param Selector|null      $selector      Обязателен для всех действий, кроме keep.
     * @param array<int, string> $detachColumns Заполняется только для detach.
     */
    public function __construct(
        TableRef $tableRef,
        TableAction $action,
        ?Selector $selector = null,
        string $primaryKey = 'id',
        array $detachColumns = []
    ) {
        if ($action->readsRows() && $selector === null) {
            throw new InvalidArgumentException(
                'Правило ' . $tableRef->key() . ' с действием ' . $action->value() . ' требует селектор.'
            );
        }

        if ($action->isKeep() && $selector !== null) {
            throw new InvalidArgumentException(
                'Правило keep для ' . $tableRef->key() . ' не должно нести селектор: строки не читаются.'
            );
        }

        if ($action->value() === TableAction::DETACH && $detachColumns === []) {
            throw new InvalidArgumentException(
                'Правило detach для ' . $tableRef->key() . ' требует список обнуляемых колонок.'
            );
        }

        if ($primaryKey === '') {
            throw new InvalidArgumentException('Первичный ключ не может быть пустым.');
        }

        $this->tableRef = $tableRef;
        $this->action = $action;
        $this->selector = $selector;
        $this->primaryKey = $primaryKey;
        $this->detachColumns = array_values(array_unique($detachColumns));
    }

    public static function keep(TableRef $tableRef): self
    {
        return new self($tableRef, TableAction::keep());
    }

    public static function backupAndDelete(
        TableRef $tableRef,
        Selector $selector,
        string $primaryKey = 'id'
    ): self {
        return new self($tableRef, TableAction::backupAndDelete(), $selector, $primaryKey);
    }

    /**
     * @param array<int, string> $columns
     */
    public static function detach(
        TableRef $tableRef,
        Selector $selector,
        array $columns,
        string $primaryKey = 'id'
    ): self {
        return new self($tableRef, TableAction::detach(), $selector, $primaryKey, $columns);
    }

    public function tableRef(): TableRef
    {
        return $this->tableRef;
    }

    public function action(): TableAction
    {
        return $this->action;
    }

    public function selector(): ?Selector
    {
        return $this->selector;
    }

    public function primaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * @return array<int, string>
     */
    public function detachColumns(): array
    {
        return $this->detachColumns;
    }

    /**
     * Колонки, которые обязаны существовать в схеме до начала операции.
     *
     * @return array<int, string>
     */
    public function requiredColumns(): array
    {
        if ($this->selector === null) {
            return [];
        }

        $columns = array_merge($this->selector->columns(), $this->detachColumns);

        if ($this->action->deletesRows()) {
            $columns[] = $this->primaryKey;
        }

        return array_values(array_unique($columns));
    }

    /**
     * Таблицы, строки которых должны оставаться на месте в момент применения правила.
     *
     * @return array<string, TableRef>
     */
    public function parents(): array
    {
        if ($this->selector === null) {
            return [];
        }

        return (new SelectorParents())->collect($this->selector);
    }
}
