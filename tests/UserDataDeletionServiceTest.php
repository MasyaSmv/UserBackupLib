<?php

declare(strict_types=1);

namespace Tests;

use App\Services\DatabaseService;
use App\Services\UserDataDeletionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @allure.suite("UserBackup")
 * @allure.epic("UserBackupLib")
 * @allure.owner("backend")
 * @allure.lead("backend")
 * @allure.layer("integration")
 * @allure.tag("cleanup", "backup")
 */
class UserDataDeletionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('testing')->dropIfExists('positions');
        Schema::connection('testing')->dropIfExists('transactions');
        Schema::connection('testing')->dropIfExists('users');

        Schema::connection('testing')->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection('testing')->create('transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->decimal('amount', 10, 2);
        });

        Schema::connection('testing')->create('positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('active_id');
            $table->string('symbol');
        });

        $this->seedData();
    }

    /**
     * @allure.title("Удаление данных пользователя после бэкапа")
     * @allure.description("Проверяем, что сервис корректно удаляет данные по user/account/active фильтрам из всех таблиц.")
     * @allure.severity(critical)
     * @allure.story("Очистка данных после резервного копирования")
     */
    public function test_it_deletes_user_related_data(): void
    {
        $databaseService = new DatabaseService(['testing']);
        $deletionService = new UserDataDeletionService($databaseService);

        $deletionService->deleteUserData(
            userId: 1,
            accountIds: [1001],
            activeIds: [501],
            ignoredTables: [],
        );

        $connection = app('db')->connection('testing');

        $this->assertSame(0, $connection->table('users')->where('id', 1)->count());
        $this->assertSame(1, $connection->table('users')->count());

        $this->assertSame(0, $connection->table('transactions')->where('account_id', 1001)->count());
        $this->assertSame(1, $connection->table('transactions')->count());

        $this->assertSame(0, $connection->table('positions')->where('user_id', 1)->count());
        $this->assertSame(1, $connection->table('positions')->count());
    }

    private function seedData(): void
    {
        $connection = app('db')->connection('testing');

        $connection->table('users')->insert([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);

        $connection->table('transactions')->insert([
            ['id' => 1, 'account_id' => 1001, 'amount' => 10.50],
            ['id' => 2, 'account_id' => 2002, 'amount' => 15.25],
        ]);

        $connection->table('positions')->insert([
            ['id' => 1, 'user_id' => 1, 'active_id' => 501, 'symbol' => 'AAPL'],
            ['id' => 2, 'user_id' => 2, 'active_id' => 777, 'symbol' => 'MSFT'],
        ]);
    }
}
