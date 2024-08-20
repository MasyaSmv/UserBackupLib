# UserBackupLib

Это PHP-библиотека, предназначенная для резервного копирования данных пользователей из нескольких баз данных MySQL.
Библиотека используется для экспорта данных пользователя и их сохранения в формате JSON с возможностью шифрования.

## Installation

Чтобы установить библиотеку в проект, добавьте следующие строки в файл composer.json:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:MasyaSmv/UserBackupLib.git"
    }
  ],
  "require": {
    "fin/user-backup-lib": "dev-main"
  }
}
```

После этого выполните команду:

```bash
composer install
```

## Использование

### Настройка подключений к базе данных

Перед использованием UserBackupService необходимо настроить подключения к базам данных с помощью класса
DatabaseConnection.

Пример:

```php
use UserBackupLib\DatabaseConnection;

$dbConfigs = [
    [
        'host' => 'localhost',
        'dbname' => 'database1',
        'user' => 'username',
        'password' => 'password',
    ],
    [
        'host' => 'localhost',
        'dbname' => 'database2',
        'user' => 'username',
        'password' => 'password',
    ],
];

$connection = new DatabaseConnection($dbConfigs);
```

## Создание резервной копии данных пользователя

После настройки подключений можно создать резервную копию данных пользователя с помощью класса UserBackupService.

Пример:

```php
use UserBackupLib\UserBackupService;

$userId = 123; // Идентификатор пользователя
$backupService = new UserBackupService($connection->getConnections(), $userId);
$backupService->backupUserData();
```

## Обработка исключений

Если данные пользователя не найдены, метод backupUserData выбросит исключение UserDataNotFoundException.

Его можно обработать следующим образом:

```php
use UserBackupLib\Exceptions\UserDataNotFoundException;

try {
    $backupService->backupUserData();
} catch (UserDataNotFoundException $e) {
    echo "Ошибка: " . $e->getMessage();
}
```

## Шифрование резервных копий

Библиотека поддерживает шифрование резервных копий с использованием алгоритма AES-256.
Шифрование выполняется автоматически после создания резервной копии, и шифрованный файл сохраняется с расширением .enc.

Структура файлов библиотеки

- `src/UserBackupService.php` - Основной класс для управления резервным копированием данных пользователя.
- `src/DatabaseConnection.php` - Класс для управления подключениями к базам данных.
- `src/exceptions/BackupException.php` - Базовый класс исключений, связанных с бэкапом.
- `src/exceptions/DatabaseConnectionException.php` - Исключение для ошибок подключения к базе данных.
- `src/exceptions/UserDataNotFoundException.php` - Исключение для ситуации, когда данные пользователя не найдены.

## Тестирование

Для запуска тестов выполните команду:

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```

## Заключение

UserBackupLib предоставляет удобные инструменты для резервного копирования данных пользователей из нескольких баз данных
MySQL.
Библиотека поддерживает шифрование резервных копий и легко интегрируется в различные проекты.
