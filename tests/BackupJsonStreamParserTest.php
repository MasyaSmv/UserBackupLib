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

    public function test_consume_json_string_returns_null_for_empty_buffer(): void
    {
        $parser = new BackupJsonStreamParser();
        $buffer = '';

        $this->assertNull($parser->consumeJsonString($buffer, 'memory'));
    }

    public function test_consume_json_string_decodes_and_mutates_buffer(): void
    {
        $parser = new BackupJsonStreamParser();
        $buffer = '"users":[';

        $result = $parser->consumeJsonString($buffer, 'memory');

        $this->assertSame('users', $result);
        $this->assertSame(':[' , $buffer);
    }

    public function test_consume_json_value_returns_incomplete_marker(): void
    {
        $parser = new BackupJsonStreamParser();
        $buffer = '{"id":1';

        $result = $parser->consumeJsonValue($buffer, 'memory');

        $this->assertSame(BackupJsonStreamParser::INCOMPLETE_JSON_VALUE, $result);
    }

    public function test_decode_json_token_throws_for_invalid_json(): void
    {
        $parser = new BackupJsonStreamParser();
        $buffer = 'invalid';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode JSON token');

        $parser->decodeJsonToken('invalid', 'memory', $buffer, 0);
    }

    public function test_parse_throws_on_invalid_row_delimiter_after_complete_value(): void
    {
        $parser = new BackupJsonStreamParser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode JSON token');

        iterator_to_array($parser->parse(['{"users":[1', 'x]}'], 'memory'), false);
    }
}
