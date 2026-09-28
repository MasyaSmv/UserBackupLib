<?php

declare(strict_types=1);

namespace UserDataBackup\Services\Compression;

use UserDataBackup\Contracts\BackupStreamCompressor;
use UserDataBackup\Exceptions\BackupCompressionException;
use Generator;

/**
 * Потоковое gzip-сжатие встроенным zlib: файл не держится в памяти целиком.
 *
 * Уровень 6 выбран замером на реальных бэкапах (WS-3280): 163 МБ JSON сжимаются до 7 МБ
 * меньше чем за секунду, а уровень 9 даёт ещё около 7% ценой заметно большего времени.
 * Формат gzip, а не raw deflate: его сигнатура `1f 8b` отличает сжатый поток от старого JSON.
 */
final class ZlibStreamCompressor implements BackupStreamCompressor
{
    public const LEVEL = 6;

    public function compress(iterable $chunks): Generator
    {
        $context = $this->open();

        foreach ($chunks as $chunk) {
            $compressed = $this->add($context, (string) $chunk, ZLIB_NO_FLUSH);

            if ($compressed !== '') {
                yield $compressed;
            }
        }

        $tail = $this->add($context, '', ZLIB_FINISH);

        if ($tail !== '') {
            yield $tail;
        }
    }

    /**
     * @return resource|\DeflateContext
     */
    private function open()
    {
        $context = @deflate_init(ZLIB_ENCODING_GZIP, ['level' => self::LEVEL]);

        if ($context === false) {
            throw new BackupCompressionException(BackupCompressionException::STAGE_INIT);
        }

        return $context;
    }

    /**
     * @param resource|\DeflateContext $context
     */
    private function add($context, string $chunk, int $flush): string
    {
        $compressed = @deflate_add($context, $chunk, $flush);

        if ($compressed === false) {
            throw new BackupCompressionException(BackupCompressionException::STAGE_DEFLATE);
        }

        return $compressed;
    }
}
