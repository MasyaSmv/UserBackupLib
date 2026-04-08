# UserBackupLib

[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/8.0/en.php)
[![Composer package](https://img.shields.io/badge/Composer-fin%2Fuser--backup--lib-885630?logo=composer&logoColor=white)](https://packagist.org/packages/fin/user-backup-lib)
[![Laravel Support](https://img.shields.io/badge/Laravel-8.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/docs/8.x)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-9.6-366488?logo=php&logoColor=white)](https://phpunit.de/)
[![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](/home/masya/projects/UserBackupLib/build/coverage/index.html)
[![Docs](https://img.shields.io/badge/Docs-user--backup--guide-blue)](/home/masya/projects/UserBackupLib/docs/user-backup-guide.md)

Библиотека для резервного копирования пользовательских данных из нескольких баз данных с потоковой записью, чанковым шифрованием и опциональным удалением исходных данных.

Подробная документация: [docs/user-backup-guide.md](docs/user-backup-guide.md)

## Возможности

- Потоковое чтение данных из БД без загрузки всего набора в память.
- Потоковая запись backup JSON в файл.
- Чанковое шифрование больших backup-файлов.
- Потоковое чтение backup-файлов через `streamBackupData()`.
- Ручной сценарий создания backup-сервиса.
- Контейнерный сценарий через factory.
- Очистка пользовательских данных после backup.

## Важные границы

- Пакет ориентирован в первую очередь на MySQL-подобный сценарий.
- Generic restore внутри пакета сейчас не реализован.
- Raw rows таблиц остаются массивами, потому что схема таблиц динамическая.
- Runtime-параметры backup use case не должны резолвиться напрямую из контейнера без явного scope.

## Установка

```bash
composer require fin/user-backup-lib
```

Packagist:

- https://packagist.org/packages/fin/user-backup-lib

## Быстрый старт

### Ручной сценарий

```php
use App\Services\UserBackupService;

$backup = UserBackupService::create(
    userId: 42,
    accountIds: [101, 102],
    activeIds: [501],
    ignoredTables: ['temp_logs'],
    connections: ['mysql', 'replica'],
);

$backup->fetchAllUserData();

$path = $backup->saveBackupToFile('/tmp/backup_42.json');
// вернется /tmp/backup_42.json.enc
```

### Контейнерный сценарий

```php
use App\Contracts\UserBackupServiceFactoryInterface;

$factory = app(UserBackupServiceFactoryInterface::class);

$backup = $factory->makeForUser(
    userId: 42,
    accountIds: [101, 102],
    activeIds: [501],
    ignoredTables: ['temp_logs'],
);

$backup->fetchAllUserData();
$path = $backup->saveBackupToFile(storage_path('app/backups/user-42.json'));
```

## Очистка данных

```php
use App\Contracts\UserDataDeletionServiceInterface;

$cleaner = app(UserDataDeletionServiceInterface::class);

$cleaner->deleteUserData(
    userId: 42,
    accountIds: [101, 102],
    activeIds: [501],
    ignoredTables: ['temp_logs'],
);
```

## Чтение backup

### Полная расшифровка в массив

```php
use App\Services\FileStorageService;

$data = FileStorageService::decryptFile('/tmp/backup_42.json.enc');
```

### Потоковое чтение

```php
use App\Services\FileStorageService;

$storage = new FileStorageService();

foreach ($storage->streamBackupData('/tmp/backup_42.json.enc') as $entry) {
    $table = $entry['table'];
    $row = $entry['row'];
}
```

## Конфигурация

Пакетный конфиг: [config/user-backup.php](config/user-backup.php)

```php
return [
    'connections' => ['mysql', 'replica'],
];
```

Правила:

- если `user-backup.connections` пуст, пакет берет все ключи из `database.connections`;
- для шифрования используется стандартный `APP_KEY` Laravel;
- путь сохранения backup выбирает приложение, не пакет.

## Тесты

```bash
php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text --coverage-html build/coverage
```

Текущее покрытие пакета: `100%`.

## Структура

- `src/Contracts/*` — контракты сервисов и factory.
- `src/Services/DatabaseService.php` — потоковое чтение из БД.
- `src/Services/FileStorageService.php` — запись, шифрование и чтение backup-файлов.
- `src/Services/UserBackupService.php` — orchestration backup use case.
- `src/Services/UserBackupServiceFactory.php` — factory для container-friendly сценария.
- `src/Services/UserDataDeletionService.php` — очистка данных.
- `src/ValueObjects/*` — внутренние DTO/value objects.
- `docs/user-backup-guide.md` — полная документация.
