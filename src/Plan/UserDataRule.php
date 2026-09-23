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

    private CursorKey $cursorKey;

    /**
     * @var array<int, string> Колонки, обнуляемые действием detach.
     */
    private array $detachColumns;

    private bool $scopeRoot = false;

    /**
     * @param Selector|null      $selector      Обязателен для всех действий, кроме keep.
     * @param string|CursorKey   $cursorKey     Уникальный ключ порций; строка — одна колонка.
     * @param array<int, string> $detachColumns Заполняется только для detach.
     */
    public function __construct(
        TableRef $tableRef,
        TableAction $action,
        ?Selector $selector = null,
        string|CursorKey $cursorKey = 'id',
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

        $this->tableRef = $tableRef;
        $this->action = $action;
        $this->selector = $selector;
        $this->cursorKey = CursorKey::from($cursorKey);
        $this->detachColumns = array_values(array_unique($detachColumns));
    }

    public static function keep(TableRef $tableRef): self
    {
        return new self($tableRef, TableAction::keep());
    }

    public static function backupAndDelete(
        TableRef $tableRef,
        Selector $selector,
        string|CursorKey $cursorKey = 'id'
    ): self {
        return new self($tableRef, TableAction::backupAndDelete(), $selector, $cursorKey);
    }

    /**
     * Строки уходят в снимок, но остаются в базе: восстановление вернёт их значения
     * upsert-ом, а удалять такую строку нельзя.
     */
    public static function backupOnly(
        TableRef $tableRef,
        Selector $selector,
        string|CursorKey $cursorKey = 'id'
    ): self {
        return new self($tableRef, TableAction::backupOnly(), $selector, $cursorKey);
    }

    /**
     * @param array<int, string> $columns
     */
    public static function detach(
        TableRef $tableRef,
        Selector $selector,
        array $columns,
        string|CursorKey $cursorKey = 'id'
    ): self {
        return new self($tableRef, TableAction::detach(), $selector, $cursorKey, $columns);
    }

    /**
     * Копия правила, помеченная как корень скоупа: таблица, из строк которой выводится сам
     * скоуп (обычно `users`).
     *
     * Такая строка обрабатывается после всех остальных правил плана. Остальные правила
     * отбирают строки по значениям скоупа и зависимости от корня через селектор не
     * объявляют, поэтому без метки корень попадал в произвольное место порядка. Общей
     * транзакции у плана нет: сбой после удаления корня оставлял данные без владельца, и
     * повторный запуск такую учётную запись уже не находил.
     */
    public function asScopeRoot(): self
    {
        $copy = clone $this;
        $copy->scopeRoot = true;

        return $copy;
    }

    public function isScopeRoot(): bool
    {
        return $this->scopeRoot;
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

    public function cursorKey(): CursorKey
    {
        return $this->cursorKey;
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
     * Ключ курсора нужен любому действию, которое читает строки порциями: удалению,
     * обнулению связи и выгрузке `backup_only`. Раньше он требовался только удалению, и
     * правило `detach` без ключа проходило preflight, а падало уже после чужих удалений.
     *
     * @return array<int, string>
     */
    public function requiredColumns(): array
    {
        if ($this->selector === null) {
            return [];
        }

        return array_values(array_unique(array_merge(
            $this->selector->columns(),
            $this->detachColumns,
            $this->cursorKey->columns(),
        )));
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
