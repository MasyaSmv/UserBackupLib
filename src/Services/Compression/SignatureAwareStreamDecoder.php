<?php

declare(strict_types=1);

namespace UserDataBackup\Services\Compression;

use UserDataBackup\Contracts\BackupStreamDecoder;
use UserDataBackup\Services\Internal\ChunkStreamPrefix;
use Generator;

/**
 * Распознаёт сжатый поток по сигнатуре gzip и распаковывает его, несжатый отдаёт как есть.
 *
 * Сигнатура однозначна: несжатый бэкап — это JSON, а JSON не может начинаться с байта
 * `0x1f` (управляющие символы допустимы только экранированными внутри строк). Поэтому
 * файлы, записанные до WS-3280, читаются без отдельного признака версии.
 */
final class SignatureAwareStreamDecoder implements BackupStreamDecoder
{
    private const GZIP_MAGIC = "\x1f\x8b";

    public function __construct(
        private ZlibStreamDecompressor $decompressor
    ) {
    }

    public function decode(iterable $chunks): Generator
    {
        $stream = ChunkStreamPrefix::of($chunks);
        $stream->extendTo(strlen(self::GZIP_MAGIC));

        if (str_starts_with($stream->prefix(), self::GZIP_MAGIC)) {
            yield from $this->decompressor->decompress($stream->replay());

            return;
        }

        yield from $stream->replay();
    }
}
