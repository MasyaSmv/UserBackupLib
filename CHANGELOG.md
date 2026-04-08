# Changelog

Все заметные изменения в этом проекте будут документироваться в этом файле.

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.0.0/)
и [Semantic Versioning](https://semver.org/lang/ru/).

## [1.0.1] — 2025-07-29

### Обновлено

- В `DatabaseService` и `UserBackupService` - добавлена поддержка для таблицы пользователей 

## [Unreleased]

### Изменено

- `UserBackupService::saveBackupToFile()` больше не генерирует путь к файлу внутри библиотеки.
- Путь к backup-файлу теперь обязан передаваться из приложения.
- Тесты и документация переведены на новый контракт сохранения.

### Важно

- Это breaking change для прямых вызовов `saveBackupToFile()` из приложений-потребителей.
