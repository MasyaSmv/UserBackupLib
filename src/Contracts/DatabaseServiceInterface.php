<?php

declare(strict_types=1);

namespace App\Contracts;

use App\ValueObjects\TableQueryParameters;

/**
 * Контракт для работы с многими БД и выборки данных пользователя.
 */
interface DatabaseServiceInterface
{
    /**
     * Возвращает список доступных подключений.
     *
     * @return array<int, string>
     */
    public function getConnections(): array;

    /**
     * Имена таблиц подключения (из предзагруженного снимка схемы).
     *
     * @return array<int, string>
     */
    public function getTables(string $connectionName): array;

    /**
     * Есть ли таблица в подключении (из предзагруженного снимка схемы).
     */
    public function hasTable(string $connectionName, string $table): bool;

    /**
     * Собирает данные пользователя из всех подключений для конкретной таблицы.
     *
     * @param string $table  Имя таблицы.
     * @param array  $params Параметры фильтрации (user_id/account_id/active_id).
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchUserDataFromAllDatabases(string $table, array $params): array;

    /**
     * Возвращает данные пользователя из конкретной БД.
     *
     * @param string $table
     * @param array  $params
     * @param string $connectionName
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchUserData(string $table, array $params, string $connectionName): array;

    /**
     * Стримит данные пользователя чанками для снижения потребления памяти.
     *
     * Пагинация — keyset (lazyById) по числовому первичному ключу; для составных/нечисловых
     * ключей — chunked-fallback. $params может нести спецификацию подзапроса (active_id),
     * поэтому принимается как массив (обратная совместимость) или как TableQueryParameters.
     *
     * @param string                          $table
     * @param array|TableQueryParameters      $params
     * @param string                          $connectionName
     * @param int                             $chunkSize
     *
     * @return \Generator<array<string, mixed>>
     */
    public function streamUserData(
        string $table,
        array|TableQueryParameters $params,
        string $connectionName,
        int $chunkSize = 5000
    ): \Generator;
}
