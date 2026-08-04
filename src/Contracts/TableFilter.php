<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Database\Query\Builder;

/**
 * Критерий фильтрации таблицы при выгрузке данных пользователя.
 *
 * Полиморфизм вместо ветвления: DatabaseService не знает, фильтруется ли таблица
 * литеральным списком или коррелированным подзапросом — он лишь применяет критерий.
 * Новый способ фильтрации добавляется новой реализацией без правки сервиса (OCP).
 */
interface TableFilter
{
    /**
     * Накладывает условие фильтрации на запрос по указанному полю.
     */
    public function applyTo(Builder $query, string $field): void;

    /**
     * true, если фильтр заведомо не отберёт ни одной строки и запрос можно не выполнять.
     */
    public function isEmpty(): bool;
}
