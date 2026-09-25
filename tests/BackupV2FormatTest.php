<?php

declare(strict_types=1);

namespace Tests;

use UserDataBackup\Exceptions\BackupFormatException;
use UserDataBackup\Services\FileStorageService;
use UserDataBackup\Services\Internal\BackupFormatDetector;
use UserDataBackup\Services\Internal\BackupV2JsonStreamParser;
use UserDataBackup\ValueObjects\BackupHeader;
use UserDataBackup\ValueObjects\BackupTableSection;

/**
 * Формат бэкапа второй версии: шапка с версиями и tenant, подключение у каждой секции,
 * и чтение файлов первой версии тем же сервисом (WS-3066).
 */
class BackupV2FormatTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user-backup-lib-v2-tests-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->baseDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->baseDir);
        parent::tearDown();
    }

    public function test_plain_backup_round_trips_header_and_connections(): void
    {
        $path = (new FileStorageService())->saveBackup($this->path('plain.json'), $this->header(), $this->sections(), false);

        $this->assertSame($this->expectedEntries(), iterator_to_array((new FileStorageService())->streamBackupData($path), false));
        $this->assertSame($this->header()->toArray(), (new FileStorageService())->readHeader($path)->toArray());
    }

    public function test_encrypted_backup_round_trips_header_and_connections(): void
    {
        $path = (new FileStorageService())->saveBackup($this->path('secret.json'), $this->header(), $this->sections());

        $this->assertStringEndsWith('.enc', $path);
        $this->assertSame($this->expectedEntries(), iterator_to_array((new FileStorageService())->streamBackupData($path), false));
        $this->assertSame('production', (new FileStorageService())->readHeader($path)->tenant());
    }

    /**
     * Одноимённые таблицы разных подключений раньше сливались в один ключ файла.
     */
    public function test_same_table_in_two_connections_stays_distinct(): void
    {
        $path = (new FileStorageService())->saveBackup($this->path('twins.json'), $this->header(), [
            new BackupTableSection('mysql', 'settings', [[['id' => 1]]]),
            new BackupTableSection('catalog', 'settings', [[['id' => 1]]]),
        ], false);

        $connections = array_column(iterator_to_array((new FileStorageService())->streamBackupData($path), false), 'connection');

        $this->assertSame(['mysql', 'catalog'], $connections);
    }

    public function test_backup_without_rows_keeps_readable_header(): void
    {
        $path = (new FileStorageService())->saveBackup($this->path('empty.json'), $this->header(), [
            new BackupTableSection('mysql', 'users', [[]]),
        ], false);

        $this->assertSame('{"@meta":' . json_encode($this->header()->toArray()) . ',"tables":[]}', file_get_contents($path));
        $this->assertSame([], iterator_to_array((new FileStorageService())->streamBackupData($path), false));
        $this->assertSame(BackupHeader::CURRENT_FORMAT, (new FileStorageService())->readHeader($path)->formatVersion());
    }

    public function test_legacy_file_is_read_without_connection_and_with_legacy_header(): void
    {
        $path = $this->path('legacy.json');
        @mkdir($this->baseDir, 0777, true);
        file_put_contents($path, '{"users":[{"id":1}],"custom_stocks":[{"id":7}]}');

        $entries = iterator_to_array((new FileStorageService())->streamBackupData($path), false);

        $this->assertSame([
            ['table' => 'users', 'row' => ['id' => 1], 'connection' => null],
            ['table' => 'custom_stocks', 'row' => ['id' => 7], 'connection' => null],
        ], $entries);
        $this->assertTrue((new FileStorageService())->readHeader($path)->isLegacy());
    }

    public function test_parser_is_agnostic_to_chunk_boundaries_byte_by_byte(): void
    {
        $path = (new FileStorageService())->saveBackup($this->path('bytes.json'), $this->header(), $this->sections(), false);
        $chunks = str_split((string) file_get_contents($path));

        $entries = array_map(
            static fn ($entry): array => $entry->toArray(),
            iterator_to_array((new BackupV2JsonStreamParser())->parse($chunks, 'memory'), false),
        );

        $this->assertSame($this->expectedEntries(), $entries);
    }

    /**
     * Решение о версии принимается, даже если маркер разрезан между чанками, и прочитанные
     * для решения чанки возвращаются в поток.
     */
    public function test_detector_decides_across_split_marker_and_replays_chunks(): void
    {
        [$format, $chunks] = (new BackupFormatDetector())->detect(['  {', ' "@m', 'eta":{}', ',"tables":[]}']);

        $this->assertSame(BackupHeader::CURRENT_FORMAT, $format);
        $this->assertSame('  { "@meta":{},"tables":[]}', implode('', iterator_to_array($chunks, false)));

        [$legacy] = (new BackupFormatDetector())->detect(['{"@me', 'ssages":[]}']);

        $this->assertSame(BackupHeader::LEGACY_FORMAT, $legacy);
    }

    public function test_unknown_format_version_is_rejected(): void
    {
        $this->expectException(BackupFormatException::class);
        $this->expectExceptionMessage('Unsupported backup format version');

        iterator_to_array((new BackupV2JsonStreamParser())->parse(['{"@meta":{"format_version":3},"tables":[]}'], 'memory'));
    }

    public function test_truncated_file_is_rejected(): void
    {
        $this->expectException(BackupFormatException::class);
        $this->expectExceptionMessage('Unexpected end of backup stream');

        iterator_to_array((new BackupV2JsonStreamParser())->parse([
            '{"@meta":{"format_version":2},"tables":[{"connection":"mysql","table":"users","rows":[{"id":1}',
        ], 'memory'));
    }

    public function test_section_keys_out_of_order_are_rejected(): void
    {
        $this->expectException(BackupFormatException::class);
        $this->expectExceptionMessage('expected key "connection"');

        iterator_to_array((new BackupV2JsonStreamParser())->parse([
            '{"@meta":{"format_version":2},"tables":[{"table":"users","connection":"mysql","rows":[]}]}',
        ], 'memory'));
    }

    private function header(): BackupHeader
    {
        return BackupHeader::current('plan-v1', 'portfolio_reset', 'production', 42, '2026-09-24T10:00:00+00:00');
    }

    /**
     * @return array<int, BackupTableSection>
     */
    private function sections(): array
    {
        return [
            new BackupTableSection('mysql', 'users', [[['id' => 42, 'name' => 'Анна, "А" ]']]]),
            new BackupTableSection('mysql', 'empty_table', [[]]),
            new BackupTableSection('catalog', 'custom_stocks', [
                [['id' => 1, 'user_id' => 'production-42']],
                [(object) ['id' => 2, 'user_id' => 'production-42', 'meta' => ['a' => [1, 2]]]],
            ]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expectedEntries(): array
    {
        return [
            ['table' => 'users', 'row' => ['id' => 42, 'name' => 'Анна, "А" ]'], 'connection' => 'mysql'],
            ['table' => 'custom_stocks', 'row' => ['id' => 1, 'user_id' => 'production-42'], 'connection' => 'catalog'],
            ['table' => 'custom_stocks', 'row' => ['id' => 2, 'user_id' => 'production-42', 'meta' => ['a' => [1, 2]]], 'connection' => 'catalog'],
        ];
    }

    private function path(string $name): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . $name;
    }
}
