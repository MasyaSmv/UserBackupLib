<?php

declare(strict_types=1);

namespace Tests;

use App\Exceptions\BackupFormatException;
use App\Exceptions\FileStorageException;
use App\Services\FileStorageService;
use RuntimeException;

class FileStorageServiceTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user-backup-lib-file-tests';
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function test_decrypt_file_throws_when_file_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Encrypted file not found');

        FileStorageService::decryptFile($this->baseDir . DIRECTORY_SEPARATOR . 'missing.json.enc');
    }

    public function test_decrypt_file_reads_plain_json_file(): void
    {
        $path = $this->makePath('plain.json');
        $this->writeFile($path, '{"users":[{"id":1,"name":"Alice"}]}');

        $data = FileStorageService::decryptFile($path);

        $this->assertSame('Alice', $data['users'][0]['name']);
    }

    public function test_decrypt_file_reads_legacy_single_line_encrypted_payload(): void
    {
        $path = $this->makePath('legacy.json.enc');
        $this->writeFile($path, encrypt('{"users":[{"id":1,"name":"Alice"}]}', false));

        $data = FileStorageService::decryptFile($path);

        $this->assertSame('Alice', $data['users'][0]['name']);
    }

    public function test_decrypt_file_throws_on_invalid_json(): void
    {
        $path = $this->makePath('invalid.json');
        $this->writeFile($path, '{"users":[');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of backup stream');

        FileStorageService::decryptFile($path);
    }

    public function test_decrypt_file_handles_empty_backup_object(): void
    {
        $path = $this->makePath('empty-object.json');
        $this->writeFile($path, '{}');

        $this->assertSame([], FileStorageService::decryptFile($path));
    }

    public function test_stream_backup_data_throws_on_invalid_prefix(): void
    {
        $path = $this->makePath('invalid-prefix.json');
        $this->writeFile($path, '[]');

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected object start');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_stream_backup_data_throws_on_unexpected_end_after_empty_file(): void
    {
        $path = $this->makePath('empty-file.json');
        $this->writeFile($path, '');

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of backup stream');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_stream_backup_data_throws_on_invalid_table_separator(): void
    {
        $path = $this->makePath('invalid-separator.json');
        $this->writeFile($path, '{"users"[{"id":1}]}');

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected ":" after table name');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_stream_backup_data_throws_when_table_name_is_not_json_string(): void
    {
        $path = $this->makePath('invalid-table-name.json');
        $this->writeFile($path, '{users:[{"id":1}]}');

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected JSON string');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_stream_backup_data_throws_on_incomplete_table_name(): void
    {
        $path = $this->makePath('incomplete-table-name.json');
        $this->writeFile($path, '{"users');

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of backup stream');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_stream_backup_data_throws_on_invalid_array_start(): void
    {
        $path = $this->makePath('invalid-array.json');
        $this->writeFile($path, '{"users":{"id":1}}');

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected "[" after table name');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_stream_backup_data_throws_on_invalid_row_delimiter(): void
    {
        $path = $this->makePath('invalid-row-delimiter.json');
        $this->writeFile($path, '{"users":[1x]}');

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode JSON token');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_stream_backup_data_throws_on_invalid_table_delimiter(): void
    {
        $path = $this->makePath('invalid-table-delimiter.json');
        $this->writeFile($path, '{"users":[{"id":1}]"transactions":[]}');

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected "," or "}" after table payload');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_save_to_file_normalizes_objects_and_scalars(): void
    {
        $storage = new FileStorageService();
        $path = $this->makePath('normalized.json');

        $storage->saveToFile($path, [
            'users' => [[
                (object) ['id' => 1, 'name' => 'Alice'],
                'marker',
            ]],
        ], false);

        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertSame(['id' => 1, 'name' => 'Alice'], $payload['users'][0]);
        $this->assertSame('marker', $payload['users'][1]);
    }

    public function test_stream_backup_data_handles_nested_arrays_and_escaped_strings(): void
    {
        $path = $this->makePath('nested.json');
        $this->writeFile(
            $path,
            '{"users":[{"meta":{"tags":["a","b"],"name":"A\\\\\"B"}}]}',
        );

        $entries = iterator_to_array((new FileStorageService())->streamBackupData($path), false);

        $this->assertSame(['a', 'b'], $entries[0]['row']['meta']['tags']);
        $this->assertSame('A\"B', $entries[0]['row']['meta']['name']);
    }

    public function test_stream_backup_data_handles_extra_encrypted_lines_after_done_state(): void
    {
        $path = $this->makePath('done-state.json.enc');
        $this->writeFile(
            $path,
            encrypt('{}', false) . PHP_EOL . encrypt(' ', false) . PHP_EOL,
        );

        $entries = iterator_to_array((new FileStorageService())->streamBackupData($path), false);

        $this->assertSame([], $entries);
    }

    public function test_stream_backup_data_throws_on_incomplete_array_payload(): void
    {
        $path = $this->makePath('incomplete-array-payload.json');
        $this->writeFile($path, '{"users":[');

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of backup stream');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_stream_backup_data_throws_on_incomplete_row_value(): void
    {
        $path = $this->makePath('incomplete-row-value.json');
        $this->writeFile($path, '{"users":[{"id":1');

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected end of backup stream');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_stream_backup_data_throws_on_invalid_json_string_token(): void
    {
        $path = $this->makePath('invalid-json-string-token.json');
        $this->writeFile($path, "{\"bad\\q\":[]}");

        $storage = new FileStorageService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode JSON string from backup');

        iterator_to_array($storage->streamBackupData($path), false);
    }

    public function test_save_to_file_replaces_existing_temp_file(): void
    {
        $storage = new FileStorageService();
        $path = $this->makePath('replace-temp.json');
        $tempPath = $path . '.tmp';

        $this->writeFile($tempPath, 'stale');

        $storage->saveToFile($path, [
            'users' => [['marker']],
        ], false);

        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($tempPath);
    }

    public function test_save_to_file_supports_non_iterable_table_chunk_values(): void
    {
        $storage = new FileStorageService();
        $path = $this->makePath('scalar-chunk.json');

        $storage->saveToFile($path, [
            'users' => ['single'],
        ], false);

        $payload = json_decode((string) file_get_contents($path), true);

        $this->assertSame(['single'], $payload['users']);
    }

    public function test_save_to_file_throws_when_temp_file_cannot_be_opened(): void
    {
        $storage = new FileStorageService();

        $this->expectException(FileStorageException::class);
        $this->expectExceptionMessage('Unable to open temp file for writing');

        $storage->saveToFile('/proc/self/status', ['users' => [[]]], false);
    }

    public function test_save_to_file_throws_when_target_rename_fails(): void
    {
        $storage = new FileStorageService();
        $path = $this->makePath('existing-dir-target');
        mkdir($path, 0777, true);

        $this->expectException(FileStorageException::class);
        $this->expectExceptionMessage('Unable to move temp file');

        $storage->saveToFile($path, ['users' => [[]]], false);
    }

    public function test_save_to_file_throws_when_row_cannot_be_encoded(): void
    {
        $storage = new FileStorageService();
        $path = $this->makePath('nan.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode backup row to JSON');

        $storage->saveToFile($path, ['users' => [[NAN]]], false);
    }

    public function test_save_to_file_throws_when_directory_cannot_be_created(): void
    {
        $storage = new FileStorageService();

        $this->expectException(FileStorageException::class);
        $this->expectExceptionMessage('was not created');

        $storage->saveToFile('/dev/null/backup.json', ['users' => [[]]], false);
    }

    public function test_stream_backup_data_throws_when_plain_path_is_directory(): void
    {
        $path = $this->makePath('plain-dir');
        mkdir($path, 0777, true);

        $this->expectException(FileStorageException::class);
        $this->expectExceptionMessage('Failed to read file');

        iterator_to_array((new FileStorageService())->streamBackupData($path), false);
    }

    public function test_stream_backup_data_throws_when_encrypted_path_is_directory(): void
    {
        $path = $this->makePath('encrypted-dir.enc');
        mkdir($path, 0777, true);

        $this->expectException(BackupFormatException::class);
        $this->expectExceptionMessage('Unexpected end of backup stream');

        iterator_to_array((new FileStorageService())->streamBackupData($path), false);
    }

    public function test_stream_backup_data_handles_chunk_boundaries_between_states(): void
    {
        $path = $this->makePath('chunk-boundaries.json.enc');
        $this->writeFile(
            $path,
            encrypt('{', false) . PHP_EOL .
            encrypt('"users"', false) . PHP_EOL .
            encrypt(':', false) . PHP_EOL .
            encrypt('[', false) . PHP_EOL .
            encrypt('{"id":1}', false) . PHP_EOL .
            encrypt(']}', false) . PHP_EOL,
        );

        $entries = iterator_to_array((new FileStorageService())->streamBackupData($path), false);

        $this->assertCount(1, $entries);
        $this->assertSame(1, $entries[0]['row']['id']);
    }

    public function test_it_creates_internal_reader_and_parser_instances(): void
    {
        $service = new class extends FileStorageService {
            public function reader(): object
            {
                return $this->createChunkReader();
            }

            public function parser(): object
            {
                return $this->createParser();
            }
        };

        $this->assertInstanceOf(\App\Services\Internal\BackupChunkReader::class, $service->reader());
        $this->assertInstanceOf(\App\Services\Internal\BackupJsonStreamParser::class, $service->parser());
    }

    public function test_save_to_file_handles_empty_read_chunk_during_encryption(): void
    {
        $adapter = new class extends \App\Services\Internal\FileSystemAdapter {
            public array $written = [];

            public function ensureDirectory(string $directoryPath): void
            {
            }

            public function delete(string $path): void
            {
            }

            public function openForWrite(string $path)
            {
                $handle = fopen('php://temp', 'w+b');
                $this->written[$path] = $handle;

                return $handle;
            }

            public function openForRead(string $path, bool $encrypted = false)
            {
                return fopen('php://temp', 'rb');
            }

            public function close($handle): void
            {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        };

        $storage = new FileStorageService($adapter);
        $path = $this->makePath('empty-encrypted.json');

        $result = $storage->saveToFile($path, ['users' => [[]]], true);

        $this->assertSame($path . '.enc', $result);
        $this->assertArrayHasKey($path . '.enc', $adapter->written);
    }

    private function makePath(string $filename): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . $filename;
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function cleanup(): void
    {
        if (!is_dir($this->baseDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->baseDir);
    }
}
