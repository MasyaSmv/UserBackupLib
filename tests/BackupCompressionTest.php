<?php

declare(strict_types=1);

namespace Tests;

use Generator;
use Illuminate\Support\Facades\Crypt;
use UserDataBackup\Contracts\BackupStreamCompressor;
use UserDataBackup\Contracts\BackupStreamDecoder;
use UserDataBackup\Exceptions\BackupDecompressionException;
use UserDataBackup\Services\Compression\SignatureAwareStreamDecoder;
use UserDataBackup\Services\Compression\ZlibStreamCompressor;
use UserDataBackup\Services\Compression\ZlibStreamDecompressor;
use UserDataBackup\Services\FileStorageService;
use UserDataBackup\ValueObjects\BackupHeader;
use UserDataBackup\ValueObjects\BackupTableSection;

/**
 * Сжатие файла бэкапа перед шифрованием (WS-3280): файл читается обратно значение в значение,
 * старые несжатые файлы читаются как раньше, оборванный сжатый поток не принимается за целый.
 */
class BackupCompressionTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user-backup-lib-compression-' . bin2hex(random_bytes(4));
        mkdir($this->baseDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->baseDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->baseDir);
        parent::tearDown();
    }

    public function test_encrypted_backup_is_compressed_before_encryption(): void
    {
        $path = (new FileStorageService())->saveBackup($this->path('snapshot.json'), $this->header(), $this->sections(3));

        $firstLine = strtok((string) file_get_contents($path), PHP_EOL);

        $this->assertStringStartsWith("\x1f\x8b", Crypt::decryptString($firstLine), 'шифруется уже сжатый поток');
    }

    public function test_compressed_backup_round_trips_rows_and_header(): void
    {
        $storage = new FileStorageService();
        $path = $storage->saveBackup($this->path('snapshot.json'), $this->header(), $this->sections(500));

        $this->assertSame($this->expectedEntries(500), iterator_to_array($storage->streamBackupData($path), false));
        $this->assertSame($this->header()->toArray(), $storage->readHeader($path)->toArray());
    }

    public function test_compressed_file_is_several_times_smaller_than_plain_json(): void
    {
        $storage = new FileStorageService();
        $plain = $storage->saveBackup($this->path('plain.json'), $this->header(), $this->sections(20000), false);
        $compressed = $storage->saveBackup($this->path('compressed.json'), $this->header(), $this->sections(20000));

        $this->assertLessThan(filesize($plain) / 5, filesize($compressed));
    }

    /**
     * Файлы, записанные до WS-3280: построчный шифротекст несжатого JSON.
     */
    public function test_uncompressed_encrypted_file_written_before_compression_is_read(): void
    {
        $storage = new FileStorageService();
        $plain = $storage->saveBackup($this->path('old.json'), $this->header(), $this->sections(50), false);
        $legacy = $this->path('old-encrypted.json.enc');

        $lines = collect(str_split((string) file_get_contents($plain), 97))
            ->map(static fn (string $chunk): string => Crypt::encryptString($chunk))
            ->implode(PHP_EOL);
        file_put_contents($legacy, $lines . PHP_EOL);

        $this->assertSame($this->expectedEntries(50), iterator_to_array($storage->streamBackupData($legacy), false));
        $this->assertSame('production', $storage->readHeader($legacy)->tenant());
    }

    public function test_truncated_compressed_stream_is_rejected(): void
    {
        $compressed = implode('', iterator_to_array((new ZlibStreamCompressor())->compress([$this->jsonPayload(200)]), false));
        $truncated = $this->path('truncated.json.enc');
        file_put_contents($truncated, Crypt::encryptString(substr($compressed, 0, (int) (strlen($compressed) / 2))) . PHP_EOL);

        $this->expectException(BackupDecompressionException::class);

        iterator_to_array((new FileStorageService())->streamBackupData($truncated), false);
    }

    public function test_corrupted_compressed_stream_is_rejected_with_code_and_context(): void
    {
        try {
            iterator_to_array((new ZlibStreamDecompressor())->decompress(["\x1f\x8b\x08\x00garbage-garbage-garbage"]), false);
            $this->fail('повреждённый поток обязан отклоняться');
        } catch (BackupDecompressionException $exception) {
            $this->assertSame(BackupDecompressionException::CODE, $exception->errorCode());
            $this->assertSame(BackupDecompressionException::CODE, $exception->context()['error_code']);
            $this->assertGreaterThan(0, $exception->context()['compressed_bytes']);
        }
    }

    public function test_compression_round_trip_does_not_depend_on_chunk_boundaries(): void
    {
        $json = $this->jsonPayload(30);
        $compressed = implode('', iterator_to_array((new ZlibStreamCompressor())->compress(str_split($json, 7)), false));

        $restored = (new SignatureAwareStreamDecoder(new ZlibStreamDecompressor()))->decode(str_split($compressed, 1));

        $this->assertSame($json, implode('', iterator_to_array($restored, false)));
    }

    public function test_decoder_passes_uncompressed_stream_through_unchanged(): void
    {
        $decoder = new SignatureAwareStreamDecoder(new ZlibStreamDecompressor());

        // Разбивка на чанки не сохраняется: для сигнатуры декодер склеивает начало потока.
        $this->assertSame('{"a":1}{"b":2}', implode('', iterator_to_array($decoder->decode(['{', '"a":1}', '{"b":2}']), false)));
        $this->assertSame([], iterator_to_array($decoder->decode([]), false));
    }

    /**
     * Распаковка отдаёт данные по мере поступления сжатых порций, а не после чтения всего файла.
     */
    public function test_decompression_streams_output_before_consuming_whole_input(): void
    {
        $plain = str_repeat($this->jsonPayload(2000), 3);
        $compressor = new ZlibStreamCompressor();
        $compressedChunks = str_split(implode('', iterator_to_array($compressor->compress(str_split($plain, 65536)), false)), 4096);

        $consumed = 0;
        $source = (static function () use ($compressedChunks, &$consumed): Generator {
            foreach ($compressedChunks as $chunk) {
                $consumed++;
                yield $chunk;
            }
        })();

        $output = (new ZlibStreamDecompressor())->decompress($source);
        $output->current();

        $this->assertGreaterThan(1, count($compressedChunks));
        $this->assertLessThan(count($compressedChunks), $consumed, 'первая порция отдана до конца входа');
    }

    public function test_provider_binds_compression_contracts(): void
    {
        $this->assertInstanceOf(ZlibStreamCompressor::class, $this->app->make(BackupStreamCompressor::class));
        $this->assertInstanceOf(SignatureAwareStreamDecoder::class, $this->app->make(BackupStreamDecoder::class));
    }

    private function header(): BackupHeader
    {
        return BackupHeader::current('plan-v1', 'portfolio_reset', 'production', 42, '2026-09-28T10:00:00+00:00');
    }

    /**
     * @return array<int, BackupTableSection>
     */
    private function sections(int $rows): array
    {
        return [
            new BackupTableSection('mysql', 'active_trades', [$this->rows($rows)]),
            new BackupTableSection('catalog', 'custom_stocks', [[['id' => 1, 'user_id' => 'production-42']]]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $count): array
    {
        return collect(range(1, $count))
            ->map(static fn (int $id): array => [
                'id' => $id,
                'user_id' => 42,
                'comment' => 'Покупка по рынку, "лот" ' . $id,
                'sum' => '1000.50',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function expectedEntries(int $rows): array
    {
        return collect($this->rows($rows))
            ->map(static fn (array $row): array => ['table' => 'active_trades', 'row' => $row, 'connection' => 'mysql'])
            ->push(['table' => 'custom_stocks', 'row' => ['id' => 1, 'user_id' => 'production-42'], 'connection' => 'catalog'])
            ->all();
    }

    private function jsonPayload(int $rows): string
    {
        return (string) json_encode(['active_trades' => $this->rows($rows)], JSON_UNESCAPED_UNICODE);
    }

    private function path(string $name): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . $name;
    }
}
