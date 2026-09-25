<?php

declare(strict_types=1);

namespace UserDataBackup\Plan\Exceptions;

/**
 * Правила плана ссылаются на подключения, которые не были переданы на проверку.
 *
 * Без схемы такого подключения нельзя отличить «таблицы нет в этом окружении» от
 * «подключение забыли передать». Раньше второе выглядело как первое: правила молча
 * выпадали из плана, операция рапортовала успех, а данные оставались в базе (WS-3105).
 */
final class UncheckedConnectionException extends PlanException
{
    public const CODE = 'user_data_plan.unchecked_connection';

    /**
     * @var array<int, string>
     */
    private array $connections;

    /**
     * @var array<int, string>
     */
    private array $tableKeys;

    /**
     * @param array<int, string> $connections Подключения без проверки.
     * @param array<int, string> $tableKeys   Правила, которые на них ссылаются.
     */
    public function __construct(array $connections, array $tableKeys)
    {
        $this->connections = array_values($connections);
        $this->tableKeys = array_values($tableKeys);

        parent::__construct(
            'План ссылается на непроверенные подключения: ' . implode(', ', $this->connections) . '.'
        );
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    /**
     * @return array<int, string>
     */
    public function connections(): array
    {
        return $this->connections;
    }

    public function context(): array
    {
        return [
            'error_code' => self::CODE,
            'connections' => $this->connections,
            'tables' => $this->tableKeys,
            'tables_count' => count($this->tableKeys),
        ];
    }
}
