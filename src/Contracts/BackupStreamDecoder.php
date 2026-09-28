<?php

declare(strict_types=1);

namespace UserDataBackup\Contracts;

use Generator;

/**
 * Возвращает исходный поток байтов бэкапа после расшифровки: распаковывает сжатый и
 * пропускает как есть несжатый, записанный до WS-3280.
 */
interface BackupStreamDecoder
{
    /**
     * @param iterable<string> $chunks Порции потока после расшифровки.
     * @return Generator<int, string> Порции исходного JSON.
     */
    public function decode(iterable $chunks): Generator;
}
