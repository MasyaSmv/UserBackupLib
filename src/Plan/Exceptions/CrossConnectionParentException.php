<?php

declare(strict_types=1);

namespace App\Plan\Exceptions;

/**
 * Правило ссылается на родителя в другом подключении.
 *
 * Подзапрос через границу подключений в SQL невозможен, а молча материализовать
 * идентификаторы в память нельзя: на таблицах в сотни тысяч строк это тихо превращается
 * в выборку всего родителя. Такая связь описывается явным набором скоупа, который
 * приложение наполняет само.
 */
final class CrossConnectionParentException extends PlanException
{
    public const CODE = 'user_data_plan.cross_connection_parent';

    private string $tableKey;

    private string $parentKey;

    public function __construct(string $tableKey, string $parentKey)
    {
        $this->tableKey = $tableKey;
        $this->parentKey = $parentKey;

        parent::__construct(
            'Таблица ' . $tableKey . ' ссылается на родителя ' . $parentKey
            . ' в другом подключении: подзапрос невозможен.'
        );
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'error_code' => self::CODE,
            'table' => $this->tableKey,
            'parent' => $this->parentKey,
        ];
    }
}
