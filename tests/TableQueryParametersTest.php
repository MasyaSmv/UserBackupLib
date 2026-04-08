<?php

declare(strict_types=1);

namespace Tests;

use App\ValueObjects\FilterValues;
use App\ValueObjects\TableQueryParameters;
use PHPUnit\Framework\TestCase;

class TableQueryParametersTest extends TestCase
{
    public function test_it_builds_from_array_and_exports_back(): void
    {
        $params = TableQueryParameters::fromArray([
            'user_id' => [1],
            'account_id' => [1001, 1002],
            1 => ['skip'],
            'broken' => 'skip',
        ]);

        $this->assertSame([
            'user_id' => [1],
            'account_id' => [1001, 1002],
        ], $params->toArray());
    }

    public function test_it_returns_empty_values_for_unknown_field(): void
    {
        $params = new TableQueryParameters();

        $this->assertTrue($params->valuesFor('missing')->isEmpty());
    }

    public function test_it_returns_fallback_values_when_primary_field_is_empty(): void
    {
        $params = new TableQueryParameters([
            'account_id' => new FilterValues([1001]),
        ]);

        $this->assertSame([1001], $params->valuesForWithFallback('subaccount_id', 'account_id')->toArray());
    }
}
