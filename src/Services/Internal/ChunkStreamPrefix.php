<?php

declare(strict_types=1);

namespace UserDataBackup\Services\Internal;

use Generator;
use Iterator;

/**
 * Позволяет заглянуть в начало потока чанков и затем отдать поток целиком, не теряя
 * прочитанного. Нужен тем, кто решает по первым байтам: версия формата, сжатие.
 */
final class ChunkStreamPrefix
{
    private string $prefix = '';

    private function __construct(
        private Iterator $rest
    ) {
    }

    /**
     * @param iterable<string> $chunks
     */
    public static function of(iterable $chunks): self
    {
        $generator = (static function () use ($chunks): Generator {
            yield from $chunks;
        })();

        $generator->current();

        return new self($generator);
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Дописывает к префиксу следующий чанк. `false` — поток кончился.
     */
    public function extend(): bool
    {
        if (!$this->rest->valid()) {
            return false;
        }

        $this->prefix .= (string) $this->rest->current();
        $this->rest->next();

        return true;
    }

    /**
     * Расширяет префикс, пока в нём меньше `$bytes` байт и поток не кончился.
     */
    public function extendTo(int $bytes): void
    {
        while (strlen($this->prefix) < $bytes && $this->extend()) {
        }
    }

    /**
     * @return Generator<int, string> Прочитанный префикс и остаток потока.
     */
    public function replay(): Generator
    {
        if ($this->prefix !== '') {
            yield $this->prefix;
        }

        while ($this->rest->valid()) {
            yield (string) $this->rest->current();
            $this->rest->next();
        }
    }
}
