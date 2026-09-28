<?php

declare(strict_types=1);

namespace UserDataBackup\Services\Compression;

use UserDataBackup\Exceptions\BackupDecompressionException;
use Generator;

/**
 * Потоковая распаковка gzip встроенным zlib.
 *
 * Оборванный файл zlib молча отдаёт как успешно распакованный кусок, поэтому конец сжатого
 * потока проверяется явно: без этой проверки restore принял бы бэкап с потерянными строками.
 */
final class ZlibStreamDecompressor
{
    /**
     * @param iterable<string> $chunks Порции одного gzip-потока.
     * @return Generator<int, string>
     */
    public function decompress(iterable $chunks): Generator
    {
        $context = $this->open();
        $consumed = 0;

        foreach ($chunks as $chunk) {
            $chunk = (string) $chunk;
            $consumed += strlen($chunk);
            $plain = $this->add($context, $chunk, ZLIB_SYNC_FLUSH, $consumed);

            if ($plain !== '') {
                yield $plain;
            }
        }

        // Завершающий вызов только для незаконченного потока: после конца gzip он сам переводит
        // статус из STREAM_END в BUF_ERROR, и целый файл выглядел бы оборванным.
        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            $tail = $this->add($context, '', ZLIB_FINISH, $consumed);

            if ($tail !== '') {
                yield $tail;
            }
        }

        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            throw new BackupDecompressionException(BackupDecompressionException::STAGE_TRUNCATED, $consumed);
        }
    }

    /**
     * @return resource|\InflateContext
     */
    private function open()
    {
        $context = @inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            throw new BackupDecompressionException(BackupDecompressionException::STAGE_INIT, 0);
        }

        return $context;
    }

    /**
     * @param resource|\InflateContext $context
     */
    private function add($context, string $chunk, int $flush, int $consumed): string
    {
        $plain = @inflate_add($context, $chunk, $flush);

        if ($plain === false) {
            throw new BackupDecompressionException(BackupDecompressionException::STAGE_INFLATE, $consumed);
        }

        return $plain;
    }
}
