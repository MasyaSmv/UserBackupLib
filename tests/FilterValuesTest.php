<?php

declare(strict_types=1);

namespace Tests;

use App\ValueObjects\FilterValues;
use PHPUnit\Framework\TestCase;

class FilterValuesTest extends TestCase
{
    public function test_it_normalizes_values_and_removes_duplicates(): void
    {
        $values = new FilterValues([1, '2', 1, [], '2']);

        $this->assertSame([1, '2'], $values->toArray());
    }

    public function test_it_reports_empty_state_and_chunks_values(): void
    {
        $empty = new FilterValues();
        $values = new FilterValues([1, 2, 3]);

        $this->assertTrue($empty->isEmpty());
        $this->assertSame([[1, 2], [3]], $values->chunk(2));
    }

    public function test_it_creates_single_value_set(): void
    {
        $values = FilterValues::single(42);

        $this->assertSame([42], $values->toArray());
    }
}
