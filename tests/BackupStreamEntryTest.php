<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Internal\BackupStreamEntry;
use PHPUnit\Framework\TestCase;

class BackupStreamEntryTest extends TestCase
{
    public function test_it_exposes_table_row_and_array_representation(): void
    {
        $entry = new BackupStreamEntry('users', ['id' => 1]);

        $this->assertSame('users', $entry->table());
        $this->assertSame(['id' => 1], $entry->row());
        $this->assertSame(
            ['table' => 'users', 'row' => ['id' => 1], 'connection' => null],
            $entry->toArray(),
        );
    }

    public function test_it_carries_connection_of_v2_section(): void
    {
        $entry = new BackupStreamEntry('custom_stocks', ['id' => 1], 'catalog');

        $this->assertSame('catalog', $entry->connection());
        $this->assertSame('catalog', $entry->toArray()['connection']);
    }
}
