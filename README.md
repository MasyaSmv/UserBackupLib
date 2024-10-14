# UserBackupLib (сделано специально для WhiteSwan)

UserBackupLib — библиотека для резервного копирования данных пользователей из нескольких баз данных MySQL.
Она позволяет экспортировать данные пользователя и сохранять их в формате JSON с возможностью шифрования,
обеспечивая безопасность и удобство хранения данных.

## Установка

Для установки библиотеки в проект добавьте следующие строки в файл `composer.json`:

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

Библиотека поддерживает резервное копирование данных из нескольких баз данных.
Вы можете передать список подключений при создании экземпляра `UserBackupService`, и библиотека автоматически извлечет
данные из всех указанных баз данных.

### Подключение к нескольким базам данных

В DatabaseService добавлен метод `getConnections()`, который возвращает список всех подключений, используемых для
извлечения данных:

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

## Сохранение данных в файл и шифрование

### Шифрование данных
UserBackupLib - позволяет шифровать данные перед их сохранением в файл. 
Для шифрования используется ключ, который необходимо настроить в файле .env ([см. Раздел Настройка шифрования](#настройка-шифрования)).

### Настройка шифрования
Для шифрования данных, сохраняемых библиотекой, необходимо настроить ключ шифрования в файле .env вашего проекта.

Шаги 
- Генерация ключа:
  - Используйте в
    ```bash
    php artisan key:generate --show
    ```
    Скопируйте


- Добавление ключа в .env:
  - Вставьте ключ в файл .env под переменной BACKUP_ENCRYPTION_KEY. Пример:
    ```env
    BACKUP_ENCRYPTION_KEY=base64:L+randomgeneratedkeyhere/=/=
    ```
  - Если вы хотите сгенерировать ключ вручную, вы можете использовать команду OpenSSL:
    ```bash
    openssl rand -base64 32
    ```
    Этот ключ также нужно добавить в файл .env.


- Использование ключа:
  - Библиотека будет автоматически использовать этот ключ для шифрования и расшифровки файлов. Убедитесь, что ключ безопасно хранится и не передается в репозитории.

### Пример использования saveBackupToFile:

Метод `saveBackupToFile` автоматически сохраняет и шифрует данные, а также возвращает путь до сохраненного файла:

```php
use App\Services\UserBackupService;

$userId = 680;
$connections = ['mysql', 'catalog'];

$backupService = UserBackupService::create($userId, $accountIds, $activeIds, $ignoredTables, $connections);

// Извлечение всех данных пользователя
$backupService->fetchAllUserData();

// Сохранение данных в файл и получение пути до сохраненного файла
$filePath = $backupService->saveBackupToFile(); // Использует ключ из .env для шифрования

echo "Резервная копия создана и зашифрована по пути: {$filePath}";
```

### Возврат пути до сохраненного файла

Метод `saveToFile` возвращает строку, содержащую путь до сохраненного файла.
Если файл был зашифрован, возвращается путь до зашифрованного файла с расширением `.enc`.

Пример использования saveToFile:

```php
use App\Services\FileStorageService;

$fileStorageService = new FileStorageService();
$data = ['user_id' => 123, 'name' => 'John Doe'];

$filePath = 'resources/backup_actives/3/2024-07-29/17-40-25.json';
$pathToFile = $fileStorageService->saveToFile($filePath, $data, true); // true для шифрования

echo "Файл сохранен и зашифрован по пути: {$pathToFile}";
```

### Расшифровка файлов

Библиотека также предоставляет статичный метод для расшифровки данных, что делает процесс расшифровки более удобным и простым. 
Это позволяет расшифровать файл без необходимости инициализации объекта сервиса.

Пример расшифровки данных:

```php
use App\Services\FileStorageService;

$encryptedFilePath = 'resources/backup_actives/3/2024-07-29/17-40-25.json.enc';

$data = FileStorageService::decryptFile($encryptedFilePath);

print_r($data);
```

## Структура файлов библиотеки

- `src/Services/UserBackupService.php` - Основной класс для управления резервным копированием данных пользователя.
- `src/Services/DatabaseService.php` - Класс для работы с несколькими базами данных и извлечения данных пользователя.
- `src/Services/BackupProcessor.php` - Класс для обработки и подготовки данных пользователя.
- `src/Services/FileStorageService.php` - Класс для сохранения и шифрования данных в файлы.
- `src/Exceptions/BackupException.php` - Базовый класс исключений, связанных с бэкапом.
- `src/Exceptions/UserDataNotFoundException.php` - Исключение для ситуации, когда данные пользователя не найдены.

## Тестирование (Пока не реализовано)

Для запуска тестов выполните команду:

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php tests
```

## Заключение

Библиотека предоставляет удобные инструменты для резервного копирования данных пользователей из нескольких баз данных
MySQL.
Так же поддерживает шифрование резервных копий, легко интегрируется в различные проекты, и обеспечивает высокую
степень безопасности и гибкости при работе с данными.
