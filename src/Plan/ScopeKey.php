<?php

declare(strict_types=1);

namespace App\Plan;

use InvalidArgumentException;

/**
 * Имя набора значений в скоупе пользователя, на который ссылается селектор.
 *
 * Селектор не считает значения сам — он называет нужный набор, а наполняет наборы
 * приложение. Благодаря этому строковый `user_id` каталога формата `{tenant}-{id}`
 * выражается тем же селектором, что и обычный целочисленный: отличается только набор,
 * а знание о префиксе остаётся у резолвера tenant-ключа и не попадает в правило.
 */
final class ScopeKey
{
    /** Идентификатор пользователя как целое число. */
    public const USER = 'user';

    /** Идентификатор пользователя в виде строки `{tenant}-{id}` — для каталога. */
    public const USER_TENANT_KEY = 'user_tenant_key';

    /** Идентификаторы субсчетов пользователя. */
    public const SUBACCOUNTS = 'subaccounts';

    /** Идентификаторы счетов пользователя. */
    public const ACCOUNTS = 'accounts';

    /** Идентификаторы активов пользователя. */
    public const ACTIVES = 'actives';

    private const ALL = [
        self::USER,
        self::USER_TENANT_KEY,
        self::SUBACCOUNTS,
        self::ACCOUNTS,
        self::ACTIVES,
    ];

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException('Неизвестный набор значений скоупа: ' . $value);
        }

        $this->value = $value;
    }

    public static function user(): self
    {
        return new self(self::USER);
    }

    public static function userTenantKey(): self
    {
        return new self(self::USER_TENANT_KEY);
    }

    public static function subaccounts(): self
    {
        return new self(self::SUBACCOUNTS);
    }

    public static function accounts(): self
    {
        return new self(self::ACCOUNTS);
    }

    public static function actives(): self
    {
        return new self(self::ACTIVES);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
