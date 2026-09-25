<?php

declare(strict_types=1);

namespace UserDataBackup\Services\Internal;

use UserDataBackup\Exceptions\BackupFormatException;
use UserDataBackup\ValueObjects\BackupHeader;
use Generator;

/**
 * Потоковый разбор бэкапа второй версии:
 *
 * `{"@meta":{...},"tables":[{"connection":"c","table":"t","rows":[row,...]},...]}`
 *
 * Порядок ключей фиксирован писателем (`BackupV2JsonWriter`), поэтому разбор — конечный
 * автомат по ожидаемым токенам, а не общий JSON-парсер: строки отдаются по одной, весь
 * файл в памяти не живёт. Токены (строка, значение до разделителя) читает легаси-парсер —
 * у обоих форматов одна лексика, различается только грамматика.
 */
class BackupV2JsonStreamParser
{
    private const DEFAULT_MAX_RECORD_SIZE = 134217728;

    /**
     * Линейные шаги грамматики: состояние => [вид токена, аргумент, следующее состояние].
     * `char` — ожидаемый символ, `key` — ожидаемое имя ключа, `capture` — строковое
     * значение, которое запоминается под именем аргумента.
     */
    private const STEPS = [
        'open' => ['char', '{', 'meta_key'],
        'meta_key' => ['key', '@meta', 'meta_colon'],
        'meta_colon' => ['char', ':', 'meta_value'],
        'tables_key' => ['key', 'tables', 'tables_colon'],
        'tables_colon' => ['char', ':', 'tables_open'],
        'tables_open' => ['char', '[', 'section_or_end'],
        'section_open' => ['char', '{', 'connection_key'],
        'connection_key' => ['key', 'connection', 'connection_colon'],
        'connection_colon' => ['char', ':', 'connection_value'],
        'connection_value' => ['capture', 'connection', 'connection_comma'],
        'connection_comma' => ['char', ',', 'table_key'],
        'table_key' => ['key', 'table', 'table_colon'],
        'table_colon' => ['char', ':', 'table_value'],
        'table_value' => ['capture', 'table', 'table_comma'],
        'table_comma' => ['char', ',', 'rows_key'],
        'rows_key' => ['key', 'rows', 'rows_colon'],
        'rows_colon' => ['char', ':', 'rows_open'],
        'rows_open' => ['char', '[', 'row_or_end'],
        'section_close' => ['char', '}', 'section_next'],
        'close' => ['char', '}', 'done'],
    ];

    private BackupJsonStreamParser $tokens;

    public function __construct(private int $maxRecordSize = self::DEFAULT_MAX_RECORD_SIZE)
    {
        $this->tokens = new BackupJsonStreamParser($maxRecordSize);
    }

    /**
     * @param iterable<string> $chunks
     * @return Generator<int, BackupStreamEntry>
     */
    public function parse(iterable $chunks, string $filePath): Generator
    {
        foreach ($this->events($chunks, $filePath) as $event) {
            if ($event instanceof BackupStreamEntry) {
                yield $event;
            }
        }
    }

    /**
     * Читает только шапку: разбор останавливается на ней, остальные чанки не
     * расшифровываются.
     *
     * @param iterable<string> $chunks
     */
    public function header(iterable $chunks, string $filePath): BackupHeader
    {
        foreach ($this->events($chunks, $filePath) as $event) {
            if ($event instanceof BackupHeader) {
                return $event;
            }
        }

        throw new BackupFormatException(sprintf('Backup header not found in "%s"', $filePath));
    }

    /**
     * @param iterable<string> $chunks
     * @return Generator<int, BackupHeader|BackupStreamEntry>
     */
    private function events(iterable $chunks, string $filePath): Generator
    {
        $buffer = '';
        $pos = 0;
        $state = 'open';
        $captured = ['connection' => null, 'table' => null];

        foreach ($chunks as $chunk) {
            if ($pos > 0) {
                $buffer = substr($buffer, $pos);
                $pos = 0;
            }

            $buffer .= $chunk;
            $len = strlen($buffer);

            if ($len > $this->maxRecordSize) {
                throw new BackupFormatException(sprintf(
                    'Backup record in "%s" exceeds max size of %d bytes',
                    $filePath,
                    $this->maxRecordSize,
                ));
            }

            while ($state !== 'done') {
                $this->tokens->skipWhitespace($buffer, $pos, $len);

                if ($pos >= $len) {
                    break;
                }

                if (isset(self::STEPS[$state])) {
                    [$kind, $argument, $next] = self::STEPS[$state];

                    if (!$this->step($kind, $argument, $buffer, $pos, $len, $filePath, $captured)) {
                        break;
                    }

                    $state = $next;
                    continue;
                }

                switch ($state) {
                    case 'meta_value':
                        $delimiter = null;
                        $meta = $this->tokens->consumeJsonValue($buffer, $pos, $len, $filePath, $delimiter);

                        if ($meta === BackupJsonStreamParser::INCOMPLETE_JSON_VALUE) {
                            break 2;
                        }

                        if ($delimiter !== ',') {
                            throw $this->unexpected($filePath, '"," after header');
                        }

                        $pos++;
                        $state = 'tables_key';

                        yield BackupHeader::fromArray($meta, $filePath);
                        break;

                    case 'section_or_end':
                        if ($buffer[$pos] === ']') {
                            $pos++;
                            $state = 'close';
                            break;
                        }

                        $state = 'section_open';
                        break;

                    case 'section_next':
                        $state = $this->choose($buffer[$pos], $filePath, ['section_open', 'close']);
                        $pos++;
                        break;

                    case 'row_or_end':
                        if ($buffer[$pos] === ']') {
                            $pos++;
                            $state = 'section_close';
                            break;
                        }

                        $delimiter = null;
                        $row = $this->tokens->consumeJsonValue($buffer, $pos, $len, $filePath, $delimiter);

                        if ($row === BackupJsonStreamParser::INCOMPLETE_JSON_VALUE) {
                            break 2;
                        }

                        $pos++;
                        $state = $delimiter === ',' ? 'row_or_end' : 'section_close';

                        yield new BackupStreamEntry((string) $captured['table'], $row, (string) $captured['connection']);
                        break;
                }
            }
        }

        $len = strlen($buffer);
        $this->tokens->skipWhitespace($buffer, $pos, $len);

        if ($state !== 'done' || $pos < $len) {
            throw new BackupFormatException(sprintf('Unexpected end of backup stream in "%s"', $filePath));
        }
    }

    /**
     * Выполняет линейный шаг. `false` — токен ещё не дочитан, нужен следующий чанк.
     *
     * @param array<string, string|null> $captured
     */
    private function step(
        string $kind,
        string $argument,
        string &$buffer,
        int &$pos,
        int $len,
        string $filePath,
        array &$captured
    ): bool {
        if ($kind === 'char') {
            if ($buffer[$pos] !== $argument) {
                throw $this->unexpected($filePath, '"' . $argument . '"');
            }

            $pos++;

            return true;
        }

        $value = $this->tokens->consumeJsonString($buffer, $pos, $len, $filePath);

        if ($value === null) {
            return false;
        }

        if ($kind === 'key' && $value !== $argument) {
            throw $this->unexpected($filePath, 'key "' . $argument . '"');
        }

        if ($kind === 'capture') {
            $captured[$argument] = $value;
        }

        return true;
    }

    /**
     * @param array{0: string, 1: string} $states Состояния после "," и после "]".
     */
    private function choose(string $char, string $filePath, array $states): string
    {
        if ($char === ',') {
            return $states[0];
        }

        if ($char === ']') {
            return $states[1];
        }

        throw $this->unexpected($filePath, '"," or "]" after table section');
    }

    private function unexpected(string $filePath, string $expected): BackupFormatException
    {
        return new BackupFormatException(sprintf('Invalid backup format in "%s": expected %s', $filePath, $expected));
    }
}
