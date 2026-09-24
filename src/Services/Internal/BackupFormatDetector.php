<?php

declare(strict_types=1);

namespace App\Services\Internal;

use App\ValueObjects\BackupHeader;
use Generator;
use Iterator;

/**
 * Определяет версию файла бэкапа по первому ключу и отдаёт поток чанков заново целиком.
 *
 * Вторая версия начинается с `{"@meta"`, у первой первым идёт имя таблицы. Таблицы с
 * именем `@meta` в схемах нет, поэтому признак однозначен. Прочитанные для решения
 * чанки не теряются: возвращаемый поток начинается с них.
 */
final class BackupFormatDetector
{
    private const V2_MARKER = '"@meta"';

    /**
     * @param iterable<string> $chunks
     * @return array{0: int, 1: Generator<int, string>} Версия формата и полный поток чанков.
     */
    public function detect(iterable $chunks): array
    {
        $iterator = $this->iterator($chunks);
        $prefix = '';
        $format = null;

        while ($format === null && $iterator->valid()) {
            $prefix .= (string) $iterator->current();
            $iterator->next();
            $format = $this->decide($prefix);
        }

        return [$format ?? BackupHeader::LEGACY_FORMAT, $this->replay($prefix, $iterator)];
    }

    /**
     * `null` — префикса ещё не хватает для решения.
     */
    private function decide(string $prefix): ?int
    {
        $body = ltrim($prefix);

        if ($body === '') {
            return null;
        }

        if ($body[0] !== '{') {
            return BackupHeader::LEGACY_FORMAT;
        }

        $firstKey = ltrim(substr($body, 1));

        if (strlen($firstKey) < strlen(self::V2_MARKER)) {
            return $firstKey === '' || str_starts_with(self::V2_MARKER, $firstKey)
                ? null
                : BackupHeader::LEGACY_FORMAT;
        }

        return str_starts_with($firstKey, self::V2_MARKER)
            ? BackupHeader::CURRENT_FORMAT
            : BackupHeader::LEGACY_FORMAT;
    }

    /**
     * @param iterable<string> $chunks
     */
    private function iterator(iterable $chunks): Iterator
    {
        $generator = (static function () use ($chunks): Generator {
            yield from $chunks;
        })();

        $generator->current();

        return $generator;
    }

    /**
     * @return Generator<int, string>
     */
    private function replay(string $prefix, Iterator $rest): Generator
    {
        if ($prefix !== '') {
            yield $prefix;
        }

        while ($rest->valid()) {
            yield (string) $rest->current();
            $rest->next();
        }
    }
}
