<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Internal\BackupChunkReader;
use RuntimeException;

class BackupChunkReaderTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user-backup-lib-reader-tests';
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function test_it_reads_plain_file_by_chunks(): void
    {
        $path = $this->makePath('plain.json');
        $this->writeFile($path, 'abcdef');

        $reader = new BackupChunkReader();
        $chunks = iterator_to_array($reader->iterateDecryptedChunks($path, 2), false);

        $this->assertSame(['ab', 'cd', 'ef'], $chunks);
    }

    public function test_it_reads_encrypted_file_line_by_line(): void
    {
        $path = $this->makePath('encrypted.json.enc');
        $this->writeFile($path, encrypt('{"a"', false) . PHP_EOL . encrypt(':1}', false) . PHP_EOL);

        $reader = new BackupChunkReader();
        $chunks = iterator_to_array($reader->iterateDecryptedChunks($path), false);

        $this->assertSame(['{"a"', ':1}'], $chunks);
    }

    public function test_it_skips_blank_encrypted_lines(): void
    {
        $path = $this->makePath('encrypted-with-blank.json.enc');
        $this->writeFile($path, PHP_EOL . encrypt('payload', false) . PHP_EOL . PHP_EOL);

        $reader = new BackupChunkReader();
        $chunks = iterator_to_array($reader->iterateDecryptedChunks($path), false);

        $this->assertSame(['payload'], $chunks);
    }

    public function test_it_throws_when_file_is_missing(): void
    {
        $reader = new BackupChunkReader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Encrypted file not found');

        iterator_to_array($reader->iterateDecryptedChunks($this->makePath('missing.json')), false);
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
