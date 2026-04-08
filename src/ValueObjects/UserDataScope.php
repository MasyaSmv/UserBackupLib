<?php

declare(strict_types=1);

namespace App\ValueObjects;

final class UserDataScope
{
    private int $userId;

    /**
     * В текущей интеграции пакет получает ids субсчетов.
     * Название accountIds оставлено в публичном API для обратной совместимости.
     */
    private FilterValues $subaccountIds;

    private FilterValues $activeIds;

    /**
     * @var array<int, string>
     */
    private array $ignoredTables;

    /**
     * @param array<int, int|string> $accountIds Legacy-название параметра. По факту ожидаются ids субсчетов.
     * @param array<int, int|string> $activeIds
     * @param array<int, string> $ignoredTables
     */
    public function __construct(
        int $userId,
        array $accountIds = [],
        array $activeIds = [],
        array $ignoredTables = []
    ) {
        $this->userId = $userId;
        $this->subaccountIds = new FilterValues($accountIds);
        $this->activeIds = new FilterValues($activeIds);
        $this->ignoredTables = array_values(array_unique(array_map('strval', $ignoredTables)));
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function accountIds(): FilterValues
    {
        return $this->subaccountIds;
    }

    public function subaccountIds(): FilterValues
    {
        return $this->subaccountIds;
    }

    public function activeIds(): FilterValues
    {
        return $this->activeIds;
    }

    /**
     * @return array<int, string>
     */
    public function ignoredTables(): array
    {
        return $this->ignoredTables;
    }

    public function isIgnoredTable(string $table): bool
    {
        return in_array($table, $this->ignoredTables, true);
    }

    public function backupParametersForTable(string $table): TableQueryParameters
    {
        if ($table === 'users') {
            return new TableQueryParameters([
                'id' => FilterValues::single($this->userId),
            ]);
        }

        return new TableQueryParameters([
            'user_id' => FilterValues::single($this->userId),
            'account_id' => $this->subaccountIds,
            'active_id' => $this->activeIds,
        ]);
    }

    public function deletionValuesFor(string $table, string $field): FilterValues
    {
        if ($table === 'users' && $field === 'id') {
            return FilterValues::single($this->userId);
        }

        if ($table === 'user_subaccounts' && $field === 'id') {
            return $this->subaccountIds;
        }

        if ($field === 'user_id') {
            return FilterValues::single($this->userId);
        }

        if (in_array($field, ['account_id', 'from_account_id', 'to_account_id', 'subaccount_id'], true)) {
            return $this->subaccountIds;
        }

        if ($field === 'active_id') {
            return $this->activeIds;
        }

        return new FilterValues();
    }
}
