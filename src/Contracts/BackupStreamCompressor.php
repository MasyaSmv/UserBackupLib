<?php

declare(strict_types=1);

namespace UserDataBackup\Contracts;

use Generator;

/**
 * Сжимает поток байтов бэкапа перед шифрованием: зашифрованные данные уже не сжимаются (WS-3280).
 */
interface BackupStreamCompressor
{
    /**
     * @param iterable<string> $chunks Порции исходного потока в порядке записи.
     * @return Generator<int, string> Порции сжатого потока; вместе образуют один сжатый поток.
     */
    public function compress(iterable $chunks): Generator;
}
