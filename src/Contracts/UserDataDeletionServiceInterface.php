<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Контракт сервиса очистки пользовательских данных после бэкапа.
 */
interface UserDataDeletionServiceInterface
{
    /**
     * Удаляет записи пользователя из всех таблиц во всех подключениях.
     *
     * @param int   $userId        Идентификатор пользователя.
     * @param array $accountIds    Идентификаторы счетов.
     * @param array $activeIds     Идентификаторы активов.
     * @param array $ignoredTables Таблицы, которые нужно пропустить.
     */
    public function deleteUserData(
        int $userId,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = [],
    ): void;
}
