# UserBackupLib

[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/8.0/en.php)
[![Composer package](https://img.shields.io/badge/Composer-fin%2Fuser--backup--lib-885630?logo=composer&logoColor=white)](https://packagist.org/packages/fin/user-backup-lib)
[![Laravel Support](https://img.shields.io/badge/Laravel-8.x-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/docs/8.x)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-9.6-366488?logo=php&logoColor=white)](https://phpunit.de/)

Библиотека для выгрузки, удаления и восстановления данных пользователя по **явному плану**:
какие строки принадлежат пользователю, задаёт приложение декларацией правил, а не имя колонки
`user_id`. Выгрузка и удаление отбирают строки одними и теми же селекторами, поэтому бэкап
всегда покрывает то, что будет удалено.

## Что в пакете

| Слой | Где | Что делает |
|---|---|---|
| План | `src/Plan/*` | Правила таблиц (`UserDataRule`, `TableAction`: keep / backupAndDelete / backupOnly / detach), AST селекторов (`Equals`, `InScope`, `ExistsInParent`, `AnyOf`, `MorphReference`), компиляция в `CompiledUserDataPlan`, порядок удаления с проверкой циклов |
| Исполнение | `src/Plan/Execution/*` | Обход строк keyset-курсором (в том числе составным), удаление и отвязка порциями, предохранитель по числу строк (`RowLimitGuard`) |
| Preflight | `src/Plan/Preflight/*` | Сверка плана со схемой БД до первого изменения |
| Файлы | `src/Services/FileStorageService.php` | Потоковая запись и чтение бэкапа, сжатие и шифрование порциями через `Crypt` (`APP_KEY`) |
| Сжатие | `src/Services/Compression/*` | gzip встроенным `zlib` перед шифрованием (`BackupStreamCompressor`), распаковка с распознаванием по сигнатуре (`BackupStreamDecoder`) |

Сам план собирает приложение: провайдеры правил по доменам, профили операций, подключения.

## Формат файла

- **v2** (`saveBackup`) — шапка `@meta` (владелец, версия формата и плана) и секции таблиц с
  подключением: `{"@meta":{...},"tables":[{"connection":"mysql","table":"...","rows":[...]}]}`.
- **v1** (`saveToFile`) — `{"table":[rows]}` без шапки. Пишется только для совместимости и
  тестов, читается всегда.

Запись атомарная: файл пишется во временный, порция дописывается до конца, закрытие
проверяется, готовый файл публикуется переименованием. Недописанный бэкап не может выглядеть
готовым (`BackupWriteIncompleteException`, `BackupFileCloseException`).

### Сжатие и шифрование

Зашифрованный файл (`*.enc`) — это строки, каждая строка — шифротекст `Crypt` порции
**сжатого** JSON. Сжатие делается до шифрования: шифротекст уже не сжимается, а base64 в
конверте `Crypt` раздувает данные ещё в 1,78 раза.

- Формат — gzip, `zlib` уровня 6, потоково (`deflate_init` / `inflate_init`): файл целиком в
  память не читается. Замер на реальных бэкапах: 163 МБ JSON → 7 МБ меньше чем за секунду,
  итоговый файл в 13–30 раз меньше прежнего.
- Файлы, записанные до сжатия, читаются без изменений: сжатый поток распознаётся по сигнатуре
  gzip `1f 8b` после расшифровки, а JSON с байта `0x1f` начинаться не может.
- Оборванный или повреждённый сжатый поток не принимается за целый:
  `BackupDecompressionException` (`user_backup.decompression_failed`, стадия и число
  прочитанных байт в `context()`). Ошибка сжатия — `BackupCompressionException`.
- Незашифрованный файл (`encrypt: false`) пишется простым JSON без сжатия.
- Сжатие и распаковка подменяются биндингом контрактов `BackupStreamCompressor` и
  `BackupStreamDecoder`; `FileStorageService` о формате сжатия не знает.

## Чтение

```php
use UserDataBackup\Contracts\FileStorageServiceInterface;

$storage = app(FileStorageServiceInterface::class);

$header = $storage->readHeader($path);          // v1 → BackupHeader::legacy()

foreach ($storage->streamBackupData($path) as $entry) {
    // ['connection' => ..., 'table' => ..., 'row' => [...]]
}
```

## Что удалено

Старый движок эвристического удаления и выгрузки по колонке `user_id`
(`UserBackupService`, `UserBackupServiceFactory`, `UserDataDeletionService`, `BackupProcessor`,
`DatabaseService`, `UserDataScope`, конфиг `user-backup.connections`) удалён: его заменил план.
Гайд [docs/user-backup-guide.md](docs/user-backup-guide.md) описывает удалённый API и оставлен
как история.

## Тесты

```bash
vendor/bin/phpunit
```
