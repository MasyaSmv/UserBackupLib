# Changelog

Все заметные изменения в этом проекте будут документироваться в этом файле.

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.0.0/)
и [Semantic Versioning](https://semver.org/lang/ru/).

## [Unreleased]

## [2.4.0] — 2026-08-05

### Исправлено

- `BackupJsonStreamParser` переведён с посимвольного среза буфера (`substr` с головы
  на каждой строке) на чтение через integer-курсор с ленивой компактизацией раз на
  входной чанк. Сложность разбора снижена с O(n²) до O(n): стриминг реального
  бэкапа 387 МБ / 386k строк ускорился с 11+ минут до ~39 c при ограниченной памяти.
  Это снимало непрактичную задержку restore для крупных пользователей.

### Добавлено

- Параметр `BackupJsonStreamParser::__construct(int $maxRecordSize)` — лимит размера
  незавершённой записи (по умолчанию 128 MiB) как защита от OOM на битом файле без
  разделителей.
- Тест линейности парсера (N vs 2N) и edge-кейсы границ чанков/экранирования.

## [2.1.0] — 2026-04-15

### Добавлено

- Потоковое чтение backup-файлов через `FileStorageService::streamBackupData()`.
- Внутренние компоненты файлового слоя: `BackupChunkReader`, `BackupJsonStreamParser`, `BackupStreamEntry`, `FileSystemAdapter`.
- Объектные входы `UserBackupCreateOptions` и `UserDataScope`.
- `UserBackupServiceFactoryInterface` и реализация factory для container-friendly сценария.
- Value objects `ConnectionNames`, `FilterValues`, `TableQueryParameters`.
- Покрытие тестами для потокового чтения, файлового слоя, factory и value objects.

### Изменено

- `UserBackupService::saveBackupToFile()` принимает путь из приложения и больше не генерирует его внутри библиотеки.
- `UserBackupServiceProvider` и DI-сборка переведены на контракты и factory.
- `DatabaseService`, `UserBackupService` и `UserDataDeletionService` опираются на объектный scope вместо разрозненных runtime-параметров.
- README и подробный гайд обновлены под текущие сценарии использования пакета.

### Исправлено

- Исправлены коллизии временных файлов при сохранении backup.
- Улучшена диагностика ошибок файлового слоя и формата backup.
- Поддержан фильтр таблицы `users` в `DatabaseService` и `UserBackupService`.

### Важно

- Прямые вызовы `saveBackupToFile()` теперь обязаны передавать целевой путь явно.
- Legacy-входы сохранены для обратной совместимости, но preferred API теперь объектный.

## [1.0.1] — 2025-07-29

### Обновлено

- В `DatabaseService` и `UserBackupService` добавлена поддержка для таблицы пользователей.
