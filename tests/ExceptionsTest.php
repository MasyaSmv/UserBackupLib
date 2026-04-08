<?php

declare(strict_types=1);

namespace Tests;

use App\Exceptions\BackupException;
use App\Exceptions\UserDataNotFoundException;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    public function test_backup_exception_can_be_instantiated(): void
    {
        $exception = new BackupException('backup');

        $this->assertSame('backup', $exception->getMessage());
    }

    public function test_user_data_not_found_exception_can_be_instantiated(): void
    {
        $exception = new UserDataNotFoundException('missing');

        $this->assertSame('missing', $exception->getMessage());
    }
}
