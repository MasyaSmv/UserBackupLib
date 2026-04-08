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
            ['table' => 'users', 'row' => ['id' => 1]],
            $entry->toArray(),
        );
    }
}
