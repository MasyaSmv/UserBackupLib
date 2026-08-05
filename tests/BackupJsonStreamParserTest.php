<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Internal\BackupJsonStreamParser;
use App\Services\Internal\BackupStreamEntry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackupJsonStreamParserTest extends TestCase
{
    public function test_parse_reads_entries_from_chunks(): void
    {
        $parser = new BackupJsonStreamParser();

        $entries = iterator_to_array($parser->parse([
            '{"users":',
            '[{"id":1},{"id":2}]}',
        ], 'memory'), false);

        $this->assertCount(2, $entries);
        $this->assertInstanceOf(BackupStreamEntry::class, $entries[0]);
        $this->assertSame(1, $entries[0]->row()['id']);
        $this->assertSame(2, $entries[1]->row()['id']);
    }

    public function test_parse_waits_for_next_chunk_when_first_chunk_is_empty(): void
    {
        $parser = new BackupJsonStreamParser();

        $entries = iterator_to_array($parser->parse([
            '',
            '{"users":[{"id":1}]}',
        ], 'memory'), false);

        $this->assertCount(1, $entries);
        $this->assertSame(1, $entries[0]->row()['id']);
    }

    public function test_parse_waits_for_table_delimiter_chunk_boundary(): void
    {
        $parser = new BackupJsonStreamParser();

        $entries = iterator_to_array($parser->parse([
            '{"users":[{"id":1}]',
            '}',
        ], 'memory'), false);

        $this->assertCount(1, $entries);
        $this->assertSame(1, $entries[0]->row()['id']);
    }

    public function test_parse_handles_multiple_tables(): void
    {
        $parser = new BackupJsonStreamParser();

        $entries = iterator_to_array($parser->parse([
            '{"users":[{"id":1}],"accounts":[{"id":10},{"id":11}]}',
        ], 'memory'), false);

        $this->assertCount(3, $entries);
        $this->assertSame('users', $entries[0]->table());
        $this->assertSame('accounts', $entries[1]->table());
        $this->assertSame('accounts', $entries[2]->table());
        $this->assertSame(11, $entries[2]->row()['id']);
    }

    public function test_parse_skips_empty_table_arrays(): void
    {
        $parser = new BackupJsonStreamParser();

        $entries = iterator_to_array($parser->parse([
            '{"empty":[],"users":[{"id":1}]}',
        ], 'memory'), false);

        $this->assertCount(1, $entries);
        $this->assertSame('users', $entries[0]->table());
    }

    public function test_parse_preserves_delimiters_and_braces_inside_strings(): void
    {
        $parser = new BackupJsonStreamParser();

        $entries = iterator_to_array($parser->parse([
            '{"users":[{"name":"a,b],}c","nested":{"k":[1,2]}}]}',
        ], 'memory'), false);

        $this->assertCount(1, $entries);
        $this->assertSame('a,b],}c', $entries[0]->row()['name']);
        $this->assertSame([1, 2], $entries[0]->row()['nested']['k']);
    }

    public function test_parse_handles_escaped_quotes_and_backslashes(): void
    {
        $parser = new BackupJsonStreamParser();

        $entries = iterator_to_array($parser->parse([
            '{"users":[{"q":"he said \"hi\"","path":"a\\\\b"}]}',
        ], 'memory'), false);

        $this->assertCount(1, $entries);
        $this->assertSame('he said "hi"', $entries[0]->row()['q']);
        $this->assertSame('a\\b', $entries[0]->row()['path']);
    }

    public function test_parse_is_agnostic_to_chunk_boundaries_byte_by_byte(): void
    {
        $payload = '{"users":[{"id":1,"name":"a,]b"},{"id":2}],"accounts":[{"id":9}]}';

        // Каждый байт — отдельный чанк: худший случай для границ токенов.
        $chunks = str_split($payload, 1);

        $parser = new BackupJsonStreamParser();
        $entries = iterator_to_array($parser->parse($chunks, 'memory'), false);

        $this->assertCount(3, $entries);
        $this->assertSame('a,]b', $entries[0]->row()['name']);
        $this->assertSame(2, $entries[1]->row()['id']);
        $this->assertSame('accounts', $entries[2]->table());
    }

    public function test_parse_scales_linearly_with_row_count(): void
    {
        $measure = function (int $rows): float {
            $parser = new BackupJsonStreamParser();
            $chunk = $this->buildSingleTablePayload($rows);

            $start = hrtime(true);
            $count = 0;
            foreach ($parser->parse([$chunk], 'memory') as $_) {
                $count++;
            }
            $elapsed = (hrtime(true) - $start) / 1e9;

            $this->assertSame($rows, $count);

            return $elapsed;
        };

        $n = 100_000;
        $measure($n);              // прогрев
        $timeN = $measure($n);
        $time2N = $measure(2 * $n);

        // O(n): удвоение входа удваивает время. O(n^2) дало бы ~4x и минуты
        // абсолютного времени. Запас 3.0 гасит шум планировщика.
        $this->assertLessThan(
            max($timeN * 3.0, 0.05),
            $time2N,
            sprintf('Парсер не линеен: N=%.3fs, 2N=%.3fs', $timeN, $time2N),
        );
    }

    public function test_parse_throws_when_record_exceeds_max_size(): void
    {
        // Маленький лимит: одна незакрытая запись должна упереться в него,
        // а не расти до бесконечности (защита от OOM на битом файле).
        $parser = new BackupJsonStreamParser(64);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds max size');

        iterator_to_array($parser->parse([
            '{"users":[{"blob":"' . str_repeat('x', 200),
        ], 'memory'), false);
    }

    public function test_consume_json_string_returns_null_for_empty_buffer(): void
    {
        $parser = new BackupJsonStreamParser();
        $buffer = '';
        $pos = 0;

        $this->assertNull($parser->consumeJsonString($buffer, $pos, 0, 'memory'));
        $this->assertSame(0, $pos);
    }

    public function test_consume_json_string_decodes_and_advances_cursor(): void
    {
        $parser = new BackupJsonStreamParser();
        $buffer = '"users":[';
        $pos = 0;

        $result = $parser->consumeJsonString($buffer, $pos, strlen($buffer), 'memory');

        $this->assertSame('users', $result);
        // Курсор за закрывающей кавычкой; буфер не мутируется.
        $this->assertSame(7, $pos);
        $this->assertSame(':[', substr($buffer, $pos));
    }

    public function test_consume_json_string_returns_null_when_unterminated(): void
    {
        $parser = new BackupJsonStreamParser();
        $buffer = '"users';
        $pos = 0;

        $this->assertNull($parser->consumeJsonString($buffer, $pos, strlen($buffer), 'memory'));
        $this->assertSame(0, $pos);
    }

    public function test_consume_json_value_returns_incomplete_marker(): void
    {
        $parser = new BackupJsonStreamParser();
        $buffer = '{"id":1';
        $pos = 0;

        $result = $parser->consumeJsonValue($buffer, $pos, strlen($buffer), 'memory');

        $this->assertSame(BackupJsonStreamParser::INCOMPLETE_JSON_VALUE, $result);
        $this->assertSame(0, $pos);
    }

    public function test_consume_json_value_advances_cursor_to_delimiter(): void
    {
        $parser = new BackupJsonStreamParser();
        $buffer = '{"id":1},{"id":2}]';
        $pos = 0;
        $delimiter = null;

        $result = $parser->consumeJsonValue($buffer, $pos, strlen($buffer), 'memory', $delimiter);

        $this->assertSame(['id' => 1], $result);
        $this->assertSame(',', $delimiter);
        $this->assertSame('{"id":1}', substr($buffer, 0, $pos));
        $this->assertSame(',', $buffer[$pos]);
    }

    public function test_decode_json_token_throws_for_invalid_json(): void
    {
        $parser = new BackupJsonStreamParser();
        $buffer = 'invalid';
        $pos = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode JSON token');

        $parser->decodeJsonToken('invalid', 'memory', $pos, 0);
    }

    public function test_parse_throws_on_invalid_row_delimiter_after_complete_value(): void
    {
        $parser = new BackupJsonStreamParser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode JSON token');

        iterator_to_array($parser->parse(['{"users":[1', 'x]}'], 'memory'), false);
    }

    private function buildSingleTablePayload(int $rows): string
    {
        $parts = [];
        for ($i = 0; $i < $rows; $i++) {
            $parts[] = '{"id":' . $i . ',"name":"row-' . $i . '"}';
        }

        return '{"t":[' . implode(',', $parts) . ']}';
    }
}
