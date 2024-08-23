# UserBackupLib

UserBackupLib — это мощная PHP-библиотека для резервного копирования данных пользователей из нескольких баз данных
MySQL.
Библиотека позволяет экспортировать данные пользователя и сохранять их в формате JSON с возможностью шифрования,
обеспечивая безопасность и удобство хранения данных.

## Установка

Для установки библиотеки в проект добавьте следующие строки в файл composer.json:

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

Затем выполните команду:

```bash
composer install
```

## Использование

### Работа с несколькими базами данных

UserBackupLib поддерживает резервное копирование данных из нескольких баз данных.
Вы можете передать список подключений при создании экземпляра UserBackupService, и библиотека автоматически извлечет
данные из всех указанных баз данных.

### Подключение к нескольким базам данных

В DatabaseService добавлен метод getConnections(), который возвращает список всех подключений, используемых для извлечения данных:

```php
use App\Services\DatabaseService;

$databaseService = new DatabaseService(['mysql', 'mysql_backup']);

$connections = $databaseService->getConnections();

// $connections будет содержать ['mysql', 'mysql_backup']

```

### Создание резервной копии данных пользователя

Для создания резервной копии данных пользователя используйте класс `UserBackupService`.
Теперь процесс резервного копирования стал еще проще благодаря статическому методу create, который автоматически
инициализирует все необходимые
сервисы.

Пример:

```php
use App\Services\UserBackupService;

$userId = 123; // Идентификатор пользователя
$accountIds = [1, 2, 3]; // Идентификаторы счетов пользователя (если есть)
$activeIds = [10, 20]; // Идентификаторы активов пользователя (если есть)
$ignoredTables = ['temporary_logs']; // Таблицы, которые нужно игнорировать
$connections = ['mysql', 'mysql_backup']; // Имена подключений к базам данных

// Создание сервиса резервного копирования с автоматической инициализацией
$backupService = UserBackupService::create(
    $userId, 
    $accountIds, 
    $activeIds, 
    $ignoredTables, 
    $connections,
);

// Извлечение всех данных пользователя
$backupService->fetchAllUserData();

// Сохранение данных в файл
$date = date('Y-m-d');
$time = date('H-i-s');
$filePath = base_path("resources/backup_actives/{$userId}/{$date}/{$time}.json");
$backupService->saveToFile($filePath);

echo "Резервная копия создана и сохранена по пути: {$filePath}";
```

### Обработка исключений

Если данные пользователя не найдены, метод `fetchAllUserData` выбросит исключение `UserDataNotFoundException`.
Обработка этого исключения позволяет вам управлять ситуацией, когда данные отсутствуют.

Пример:

```php
use App\Exceptions\UserDataNotFoundException;

try {
    $backupService->fetchAllUserData();
} catch (UserDataNotFoundException $e) {
    echo "Ошибка: " . $e->getMessage();
}
```

### Шифрование резервных копий

UserBackupLib поддерживает шифрование резервных копий с использованием алгоритма `AES-256`.
Шифрование выполняется автоматически после создания резервной копии, и шифрованный файл сохраняется с расширением
`.enc`.

Пример шифрования файла:

```php
$filePath = base_path("resources/backup_actives/{$userId}/{$date}/{$time}.json");
$backupService->saveToFile($filePath, true); // true означает, что файл будет зашифрован
```

## Структура файлов библиотеки

- `src/Services/UserBackupService.php` - Основной класс для управления резервным копированием данных пользователя.
- `src/Services/DatabaseService.php` - Класс для работы с несколькими базами данных и извлечения данных пользователя.
- `src/Services/BackupProcessor.php` - Класс для обработки и подготовки данных пользователя.
- `src/Services/FileStorageService.php` - Класс для сохранения и шифрования данных в файлы.
- `src/Exceptions/BackupException.php` - Базовый класс исключений, связанных с бэкапом.
- `src/Exceptions/UserDataNotFoundException.php` - Исключение для ситуации, когда данные пользователя не найдены.

## Тестирование

Для запуска тестов выполните команду:

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```

## Заключение

UserBackupLib предоставляет удобные инструменты для резервного копирования данных пользователей из нескольких баз данных MySQL. 
Библиотека поддерживает шифрование резервных копий, легко интегрируется в различные проекты, и обеспечивает высокую степень безопасности и гибкости при работе с данными.