<?php

declare(strict_types=1);

namespace App\Plan\Selector;

use App\Plan\ScopeKey;
use InvalidArgumentException;

/**
 * Строка принадлежит пользователю по полиморфной паре «тип + идентификатор».
 *
 * Фильтровать такую таблицу по одному `*_id` нельзя: значение `item_id = 5` встречается
 * в каждом типе, и выборка захватит чужие строки. Условие всегда учитывает пару целиком:
 * для каждой известной сущности задаётся свой набор значений типа и своя проверка
 * идентификатора.
 *
 * Типы, не перечисленные ни в одной ветке, принадлежности не дают: неизвестный тип
 * означает неописанную связь, и это должно всплыть на проверке плана, а не молча пройти.
 */
final class MorphReference implements Selector
{
    public const TYPE = 'morph_reference';

    private string $typeColumn;

    private string $idColumn;

    /**
     * @var array<int, MorphBranch>
     */
    private array $branches;

    /**
     * @param array<int, MorphBranch> $branches Селекторы веток проверяют только $idColumn.
     */
    public function __construct(string $typeColumn, string $idColumn, array $branches)
    {
        if ($typeColumn === '' || $idColumn === '') {
            throw new InvalidArgumentException('Колонки полиморфной пары не могут быть пустыми.');
        }

        if ($branches === []) {
            throw new InvalidArgumentException('Полиморфная связь требует хотя бы одну ветку типа.');
        }

        $seen = [];

        foreach ($branches as $branch) {
            if (!$branch instanceof MorphBranch) {
                throw new InvalidArgumentException('Ветка полиморфной связи должна быть MorphBranch.');
            }

            if ($branch->selector()->columns() !== [$idColumn]) {
                throw new InvalidArgumentException(
                    'Ветка типов ' . implode(', ', $branch->types())
                    . ' должна проверять колонку ' . $idColumn . '.'
                );
            }

            foreach ($branch->types() as $type) {
                if (isset($seen[$type])) {
                    throw new InvalidArgumentException(
                        'Тип ' . $type . ' указан в двух ветках одной полиморфной связи.'
                    );
                }

                $seen[$type] = true;
            }
        }

        $this->typeColumn = $typeColumn;
        $this->idColumn = $idColumn;
        $this->branches = array_values($branches);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function typeColumn(): string
    {
        return $this->typeColumn;
    }

    public function idColumn(): string
    {
        return $this->idColumn;
    }

    /**
     * @return array<int, MorphBranch>
     */
    public function branches(): array
    {
        return $this->branches;
    }

    /**
     * Все значения типа, которые правило считает своими.
     *
     * @return array<int, string>
     */
    public function knownTypes(): array
    {
        $types = [];

        foreach ($this->branches as $branch) {
            foreach ($branch->types() as $type) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @return array<int, string>
     */
    public function columns(): array
    {
        return [$this->typeColumn, $this->idColumn];
    }

    /**
     * @return array<int, ScopeKey>
     */
    public function scopeKeys(): array
    {
        $keys = [];

        foreach ($this->branches as $branch) {
            foreach ($branch->selector()->scopeKeys() as $key) {
                $keys[$key->value()] = $key;
            }
        }

        return array_values($keys);
    }
}
