<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Execution;

use UserDataBackup\Plan\CursorKey;
use Illuminate\Database\Query\Builder;

/**
 * Условия keyset-пагинации по ключу курсора, одиночному или составному.
 *
 * Одно место на чтение порций, удаление и выгрузку бэкапа: если хоть одна из сторон
 * режет порции иначе, выгрузка и удаление расходятся, и бэкап перестаёт быть точкой
 * восстановления (WS-3101).
 *
 * Составные условия строятся раскрытыми группами, а не конструктором строки
 * `(a, b) > (?, ?)`: так запрос одинаково работает на MySQL и sqlite и опирается на
 * префикс первичного ключа.
 */
final class KeysetCursor
{
    public function order(Builder $query, CursorKey $key): Builder
    {
        foreach ($key->columns() as $column) {
            $query->orderBy($column);
        }

        return $query;
    }

    /**
     * Строки строго после последней прочитанной: `a > x OR (a = x AND b > y) OR …`.
     *
     * @param array<string, mixed> $last Значения ключа последней строки.
     */
    public function after(Builder $query, CursorKey $key, array $last): Builder
    {
        $columns = $key->columns();

        return $query->where(static function (Builder $any) use ($columns, $last): void {
            foreach (array_keys($columns) as $position) {
                $any->orWhere(static function (Builder $branch) use ($columns, $last, $position): void {
                    foreach (array_slice($columns, 0, $position) as $equal) {
                        $branch->where($equal, '=', $last[$equal]);
                    }

                    $branch->where($columns[$position], '>', $last[$columns[$position]]);
                });
            }
        });
    }

    /**
     * Ровно строки с перечисленными ключами.
     *
     * @param array<int, array<string, mixed>> $keys
     */
    public function matching(Builder $query, CursorKey $key, array $keys): Builder
    {
        if (!$key->isComposite()) {
            $column = $key->columns()[0];

            return $query->whereIn($column, array_column($keys, $column));
        }

        return $query->where(static function (Builder $any) use ($keys): void {
            foreach ($keys as $values) {
                $any->orWhere(static function (Builder $row) use ($values): void {
                    foreach ($values as $column => $value) {
                        $row->where($column, '=', $value);
                    }
                });
            }
        });
    }
}
