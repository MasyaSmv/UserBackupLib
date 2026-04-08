<?php

declare(strict_types=1);

namespace Tests;

use App\Services\BackupProcessor;
use PHPUnit\Framework\TestCase;

class BackupProcessorTest extends TestCase
{
    public function test_it_appends_and_returns_user_data(): void
    {
        $processor = new BackupProcessor();

        $users = (static function () {
            yield ['id' => 1];
        })();
        $positions = (static function () {
            yield ['id' => 2];
        })();

        $processor->appendUserData('users', $users);
        $processor->appendUserData('positions', $positions);

        $result = $processor->getUserData();

        $this->assertSame([$users], $result['users']);
        $this->assertSame([$positions], $result['positions']);
    }

    public function test_it_clears_user_data(): void
    {
        $processor = new BackupProcessor();
        $processor->appendUserData('users', [['id' => 1]]);

        $processor->clearUserData();

        $this->assertSame([], $processor->getUserData());
    }
}
