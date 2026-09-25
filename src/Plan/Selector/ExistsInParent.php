<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Selector;

use UserDataBackup\Plan\TableRef;
use InvalidArgumentException;

/**
 * Строка принадлежит пользователю, если её ссылка ведёт на строку родителя,
 * принадлежащую пользователю.
 *
 * Так выражаются дочерние таблицы без собственного поля связи: платежи цели знают только
 * `goal_id`, а владелец определяется уже у цели. Компилируется в подзапрос к родителю,
 * поэтому дочерние строки обязаны удаляться до родительских — порядок обеспечивает план.
 */
final class ExistsInParent implements Selector
{
    public const TYPE = 'exists_in_parent';

    private string $column;

    private TableRef $parent;

    private string $parentColumn;

    private Selector $parentSelector;

    /**
     * @param string   $column         Колонка-ссылка в дочерней таблице (`goal_id`).
     * @param TableRef $parent         Родительская таблица (`mysql.active_goals`).
     * @param string   $parentColumn   Колонка родителя, на которую ведёт ссылка (`id`).
     * @param Selector $parentSelector Условие принадлежности родителя пользователю.
     */
    public function __construct(
        string $column,
        TableRef $parent,
        string $parentColumn,
        Selector $parentSelector
    ) {
        if ($column === '') {
            throw new InvalidArgumentException('Имя колонки-ссылки не может быть пустым.');
        }

        if ($parentColumn === '') {
            throw new InvalidArgumentException('Имя колонки родителя не может быть пустым.');
        }

        $this->column = $column;
        $this->parent = $parent;
        $this->parentColumn = $parentColumn;
        $this->parentSelector = $parentSelector;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function column(): string
    {
        return $this->column;
    }

    public function parent(): TableRef
    {
        return $this->parent;
    }

    public function parentColumn(): string
    {
        return $this->parentColumn;
    }

    public function parentSelector(): Selector
    {
        return $this->parentSelector;
    }

    /**
     * Колонки собственной таблицы. Колонки родителя проверяются по его правилу.
     *
     * @return array<int, string>
     */
    public function columns(): array
    {
        return [$this->column];
    }

    /**
     * @return array<int, \UserDataBackup\Plan\ScopeKey>
     */
    public function scopeKeys(): array
    {
        return $this->parentSelector->scopeKeys();
    }
}
