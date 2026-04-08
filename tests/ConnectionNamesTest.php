<?php

declare(strict_types=1);

namespace Tests;

use App\ValueObjects\ConnectionNames;
use PHPUnit\Framework\TestCase;

class ConnectionNamesTest extends TestCase
{
    public function test_it_normalizes_connection_names(): void
    {
        $connections = new ConnectionNames(['mysql', '', 'replica', 'mysql', 1]);

        $this->assertSame(['mysql', 'replica'], $connections->toArray());
    }
}
