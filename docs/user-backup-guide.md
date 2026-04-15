# UserBackupLib Guide

Навигация:

- [README](../README.md)
- [GitHub Wiki](https://github.com/MasyaSmv/UserBackupLib/wiki)
- [Laravel integration notes](https://github.com/MasyaSmv/UserBackupLib/wiki/Laravel-Integration)
- [Release notes](https://github.com/MasyaSmv/UserBackupLib/wiki/Release-Notes)

## 1. Назначение

`UserBackupLib` нужен для трех связанных сценариев:

1. собрать пользовательские данные из нескольких подключений и таблиц;
2. сохранить их в backup-файл без пиков памяти;
3. при необходимости удалить исходные данные после успешного backup.

Пакет не пытается быть универсальной системой миграции схемы и не реализует полный generic restore orchestration.

## 2. Что делает пакет

- читает пользовательские данные из БД чанками;
- определяет рабочее поле фильтрации по таблице;
- агрегирует данные по таблицам;
- пишет backup в JSON потоково;
- умеет шифровать backup по чанкам;
- умеет читать backup обратно целиком или потоково;
- умеет удалять пользовательские данные по тем же правилам фильтрации.

## 3. Что пакет не делает

- не выбирает путь сохранения сам;
- не загружает backup в облако;
- не оркестрирует бизнес-специфичное восстановление данных;
- не обещает универсальную поддержку всех СУБД;
- не строит DTO для raw rows всех таблиц, потому что таблицы динамические.

## 4. Основные концепции

### 4.1 User Scope

Внутренне backup и deletion use case теперь собираются вокруг `UserDataScope`.

Он хранит:

- `userId`
- `accountIds` как legacy-имя
- `subaccountIds` как фактический внутренний смысл этих значений
- `activeIds`
- `ignoredTables`

Это важно, потому что раньше эти параметры были разрозненными массивами и скалярами в нескольких сервисах.

### 4.2 Filter Parameters

Внутренние параметры запросов теперь нормализуются через:

- `FilterValues`
- `TableQueryParameters`

Это снижает риск перепутать поля и уменьшает количество сырых ассоциативных массивов в application-слое.

### 4.3 Factory вместо прямого resolve use case

`UserBackupService` требует runtime scope. Поэтому контейнер не должен притворяться, что может корректно собрать его напрямую без параметров.

Правильный путь:

- инфраструктурные сервисы резолвятся контейнером;
- backup use case создается через `UserBackupServiceFactoryInterface`.

## 5. Архитектура

### 5.1 Слои

`DatabaseService`

- знает, как читать из БД;
- знает, как стримить данные чанками;
- не знает, куда эти данные пойдут дальше.

`BackupProcessor`

- накапливает потоковые данные по таблицам;
- не знает про файловую систему.

`FileStorageService`

- пишет backup в JSON;
- шифрует backup;
- читает backup обратно;
- не знает ничего о бизнес-сущностях.

`UserBackupService`

- orchestration use case;
- ходит по таблицам;
- получает данные из `DatabaseService`;
- передает их в `BackupProcessor`;
- сохраняет результат через `FileStorageService`.

`UserDataDeletionService`

- orchestration use case удаления;
- использует те же правила фильтрации таблиц, что и backup.

### 5.2 Внутренние компоненты файлового слоя

Внутри файловый слой разрезан на небольшие части:

- `BackupChunkReader`
- `BackupJsonStreamParser`
- `BackupStreamEntry`
- `FileSystemAdapter`

Это сделано для тестируемости, нормализации ошибок и контроля памяти.

## 6. Поддерживаемые сценарии создания сервиса

### 6.1 Ручной сценарий

Подходит, если приложение само передает runtime-параметры и список подключений.

```php
use App\Services\UserBackupService;
use App\ValueObjects\UserBackupCreateOptions;

$options = UserBackupCreateOptions::fromLegacy(
    userId: 42,
    accountIds: [101, 102],
    activeIds: [501],
    ignoredTables: ['temp_logs'],
    connections: ['mysql', 'replica'],
);

$backup = UserBackupService::createFromOptions($options);
$backup->fetchAllUserData();
$path = $backup->saveBackupToFile('/tmp/backup_42.json');
```

Legacy-совместимый вариант по-прежнему доступен:

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
```

### 6.2 Контейнерный сценарий

Подходит, если инфраструктурные зависимости уже собираются Laravel-контейнером.

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

### 6.3 Сценарий через explicit scope

```php
use App\Contracts\UserBackupServiceFactoryInterface;
use App\ValueObjects\UserDataScope;

$scope = new UserDataScope(
    userId: 42,
    accountIds: [101, 102],
    activeIds: [501],
    ignoredTables: ['temp_logs'],
);

$backup = app(UserBackupServiceFactoryInterface::class)->make($scope);
```

`makeForUser(...)` тоже сохранен как совместимый legacy-вход, но предпочтительный путь теперь через `UserDataScope`.

## 7. Конфигурация

Файл: [config/user-backup.php](../config/user-backup.php)

```php
return [
    'connections' => [],
];
```

Поведение:

- если `connections` пуст, берутся все ключи из `database.connections`;
- если `connections` задан явно, используются только они.

Пример:

```php
return [
    'connections' => ['mysql', 'replica'],
];
```

## 8. Принцип работы backup

### 8.1 Сбор данных

`UserBackupService::fetchAllUserData()` делает следующее:

1. получает список подключений;
2. получает список таблиц для каждого подключения;
3. отбрасывает ignored tables;
4. проверяет, что таблица существует;
5. подбирает параметры фильтрации;
6. вызывает `DatabaseService::streamUserData()`;
7. передает stream в `BackupProcessor`.

Для таблицы `users` используется фильтр по `id`.

Для остальных таблиц используются связанные поля:

- `user_id`
- `account_id`
- `active_id`

в зависимости от доступной схемы таблицы.

Важно:

- параметр `accountIds` в публичных сигнатурах исторический;
- в текущей интеграции проекта туда передаются ids субсчетов;
- из-за обратной совместимости имя пока не меняется наружу.

### 8.2 Сохранение файла

`saveBackupToFile()`:

1. создает временный файл рядом с целевым;
2. пишет JSON потоково;
3. если включено шифрование, читает temp-файл чанками;
4. шифрует каждый chunk отдельно;
5. пишет результат в `*.enc`;
6. удаляет временный файл.

Это дает:

- меньшее потребление памяти;
- более предсказуемое поведение на больших backup-файлах;
- меньший риск OOM по сравнению с полным `file_get_contents()` на рабочем пути.

## 9. Формат backup

Логически backup выглядит так:

```json
{
  "users": [
    { "id": 42, "name": "Alice" }
  ],
  "transactions": [
    { "id": 1, "account_id": 101 }
  ]
}
```

При шифровании файл сохраняется как последовательность зашифрованных строк, где каждая строка соответствует отдельному чанку plaintext JSON.

## 10. Чтение backup

### 10.1 Полное чтение

```php
use App\Services\FileStorageService;

$data = FileStorageService::decryptFile('/tmp/backup_42.json.enc');
```

Результат:

```php
[
    'users' => [
        ['id' => 42, 'name' => 'Alice'],
    ],
]
```

### 10.2 Потоковое чтение

```php
use App\Services\FileStorageService;

$storage = new FileStorageService();

foreach ($storage->streamBackupData('/tmp/backup_42.json.enc') as $entry) {
    $table = $entry['table'];
    $row = $entry['row'];
}
```

Каждый элемент имеет legacy-совместимый формат:

```php
[
    'table' => 'users',
    'row' => ['id' => 42, 'name' => 'Alice'],
]
```

Внутренне пакет уже использует объект `BackupStreamEntry`, но наружу пока отдает массив ради обратной совместимости.

### 10.3 Совместимость форматов

- `.json`
  - читается потоково
- новый чанковый `.json.enc`
  - читается потоково
- legacy `.enc`
  - поддерживается по совместимости
  - на больших файлах не гарантирует ту же memory-efficiency, потому что старый формат мог хранить весь JSON как один зашифрованный блок

## 11. Очистка данных

Пример:

```php
use App\Contracts\UserDataDeletionServiceInterface;
use App\ValueObjects\UserDataScope;

$cleaner = app(UserDataDeletionServiceInterface::class);

$cleaner->deleteScope(new UserDataScope(
    userId: 42,
    accountIds: [101, 102],
    activeIds: [501],
    ignoredTables: ['temp_logs'],
));
```

Legacy-совместимый вход тоже сохранен:

```php
$cleaner->deleteUserData(
    userId: 42,
    accountIds: [101, 102],
    activeIds: [501],
    ignoredTables: ['temp_logs'],
);
```

Как работает удаление:

1. пакет получает таблицы по всем подключениям;
2. определяет возможное поле фильтрации;
3. строит набор идентификаторов для этого поля;
4. удаляет записи чанками по `500` значений.

Важно:

- текущая логика считается корректной при допущении, что `accountIds` всегда содержит ids субсчетов;
- если это допущение когда-то изменится в проекте, стратегию фильтрации нужно будет пересматривать отдельно.

## 12. Ограничения и риски

### 12.1 Restore

Generic restore внутри пакета сознательно не реализован.

Причина:

- восстановление почти всегда зависит от бизнес-правил проекта;
- порядок загрузки таблиц, конфликты id, внешние ключи и события домена редко бывают универсальными.

Разумная граница сейчас:

- пакет умеет backup и чтение backup;
- проект сам решает, как выполнять restore orchestration.

### 12.2 Поддержка БД

Сейчас библиотека ориентирована в первую очередь на MySQL-подобный сценарий.

Есть частичная ветка для SQLite в тестово-совместимом режиме, но это не означает полноценно поддержанную production-матрицу БД.

PostgreSQL стоит рассматривать как отдельную задачу развития.

### 12.3 Динамическая схема

Пакет работает с таблицами динамически, поэтому:

- raw rows остаются массивами;
- DTO вводятся только там, где есть явный контракт, а не произвольная схема таблицы.

## 13. Ошибки

Для файлового слоя введены внутренние исключения:

- `BackupException`
- `BackupFormatException`
- `BackupSerializationException`
- `BackupEncryptionException`
- `BackupDecryptionException`
- `FileStorageException`
- `UserDataNotFoundException`

Это нужно, чтобы аварийные сценарии файлового и parser-слоя были диагностируемыми и не сваливались в сырые PHP warning/error path.

## 14. Тестирование

Команда:

```bash
php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text --coverage-html build/coverage
```

На текущем состоянии:

- `108 tests`
- `100% classes`
- `100% methods`
- `100% lines`

HTML-отчет:

- `build/coverage/index.html`

## 15. Практические рекомендации

- не резолвить `UserBackupServiceInterface` напрямую из контейнера;
- использовать `UserBackupCreateOptions` и `UserDataScope` как preferred object-based входы;
- использовать factory для runtime use case;
- путь файла определять в приложении;
- restore-логику держать в проекте, а не пытаться насильно обобщить ее в пакете;
- расширять DTO только на стабильных границах, а не на динамических row payload;
- при изменениях в файловом слое не терять streaming-семантику.
