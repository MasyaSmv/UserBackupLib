# UserBackupLib

Библиотека для резервного копирования пользовательских данных из нескольких баз данных с безопасным сохранением (стриминг + чанковое шифрование) и опциональным удалением исходных данных.

## Возможности

- **Стриминг без пиков памяти**: чтение из БД чанками через `DatabaseService::streamUserData()`, запись в файл без сборки всего JSON в памяти.
- **Чанковое шифрование**: каждый фрагмент JSON шифруется отдельно, что позволяет работать с большими выгрузками.
- **DI через интерфейсы**: все сервисы объявлены через контракты и регистрируются в `UserBackupServiceProvider`.
- **Очистка данных**: `UserDataDeletionService` удаляет связанные записи после успешного бэкапа.

## Установка

```bash
composer require fin/user-backup-lib
```

> Если доступ к packagist ограничен, укажите зеркало, например:
> `composer config repo.packagist composer https://repo.packagist.org`

## Быстрый старт

```php
use App\Services\UserBackupService;

$backup = UserBackupService::create(
    userId: 42,
    accountIds: [101, 102],
    activeIds: [501],
    ignoredTables: ['temp_logs'],
    connections: ['mysql', 'replica'],
);

$backup->fetchAllUserData();          // собираем данные потоками
$path = $backup->saveBackupToFile();  // шифруем и сохраняем, вернётся путь вида resources/backup_actives/{user}/{date}/{time}.json.enc
```

## Очистка данных после бэкапа

```php
use App\Services\DatabaseService;
use App\Services\UserDataDeletionService;

$database = new DatabaseService(['mysql', 'replica']);
$cleaner = new UserDataDeletionService($database);

$cleaner->deleteUserData(
    userId: 42,
    accountIds: [101, 102],
    activeIds: [501],
    ignoredTables: ['temp_logs'],
);
```

## Расшифровка файла

```php
use App\Services\FileStorageService;

$data = FileStorageService::decryptFile('resources/backup_actives/42/2024-01-01/12-00-00.json.enc');
```

## Конфигурация

- **Ключ шифрования**: используется стандартный `APP_KEY` Laravel. Убедитесь, что он задан.
- **Подключения БД**: передайте массив имён подключений (`config/database.php`) в `UserBackupService::create` или в конструктор `DatabaseService`.
- **Игнорируемые таблицы**: список строк в параметре `$ignoredTables`.

## Тесты

```bash
composer update --no-scripts   # установка зависимостей
vendor/bin/phpunit
```

По умолчанию используется SQLite in-memory через Orchestra Testbench.

## Структура

- `src/Contracts/*` — контракты сервисов.
- `src/Services/DatabaseService.php` — потоковое чтение из БД.
- `src/Services/FileStorageService.php` — потоковая запись/шифрование.
- `src/Services/UserBackupService.php` — координация бэкапа.
- `src/Services/UserDataDeletionService.php` — удаление данных после бэкапа.
- `src/UserBackupServiceProvider.php` — регистрации в контейнере.
- `tests/*` — интеграционные тесты (Allure-нотации включены).
