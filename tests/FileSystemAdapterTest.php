<?php

declare(strict_types=1);

namespace Tests;

use App\Exceptions\FileStorageException;
use App\Services\Internal\FileSystemAdapter;
use PHPUnit\Framework\TestCase;

class FileSystemAdapterTest extends TestCase
{
    private const FAIL_LINE_SCHEME = 'failline';

    private string $baseDir;

    public static function setUpBeforeClass(): void
    {
        if (!in_array(self::FAIL_LINE_SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::FAIL_LINE_SCHEME, FailingLineStream::class);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (in_array(self::FAIL_LINE_SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::FAIL_LINE_SCHEME);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user-backup-lib-fs-tests';
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function test_exists_and_is_directory(): void
    {
        $adapter = new FileSystemAdapter();
        $dir = $this->path('dir');
        mkdir($dir, 0777, true);
        $file = $this->path('dir/file.txt');
        file_put_contents($file, 'x');

        $this->assertTrue($adapter->exists($file));
        $this->assertTrue($adapter->isDirectory($dir));
        $this->assertFalse($adapter->exists($this->path('missing')));
    }

    public function test_ensure_directory_creates_directory_and_accepts_existing_one(): void
    {
        $adapter = new FileSystemAdapter();
        $dir = $this->path('nested/a/b');

        $adapter->ensureDirectory($dir);
        $adapter->ensureDirectory($dir);

        $this->assertDirectoryExists($dir);
    }

    public function test_ensure_directory_returns_when_directory_appears_after_failed_mkdir(): void
    {
        $adapter = new class extends FileSystemAdapter {
            private int $calls = 0;

            public function isDirectory(string $path): bool
            {
                $this->calls++;

                return $this->calls >= 2;
            }
        };

        $adapter->ensureDirectory('/dev/null/virtual-race-dir');

        $this->assertTrue(true);
    }

    public function test_close_ignores_non_resource(): void
    {
        $adapter = new FileSystemAdapter();

        $adapter->close('not-a-resource');

        $this->assertTrue(true);
    }

    public function test_open_for_read_throws_plain_and_encrypted_messages(): void
    {
        $adapter = new FileSystemAdapter();

        try {
            $adapter->openForRead($this->path('missing.txt'));
            $this->fail('Expected exception was not thrown.');
        } catch (FileStorageException $exception) {
            $this->assertStringContainsString('Failed to open file', $exception->getMessage());
        }

        try {
            $adapter->openForRead($this->path('missing.enc'), true);
            $this->fail('Expected exception was not thrown.');
        } catch (FileStorageException $exception) {
            $this->assertStringContainsString('Failed to open encrypted file', $exception->getMessage());
        }
    }

    public function test_open_for_write_and_close(): void
    {
        $adapter = new FileSystemAdapter();
        $path = $this->path('write.txt');
        $adapter->ensureDirectory(dirname($path));

        $handle = $adapter->openForWrite($path);
        $adapter->write($handle, 'abc');
        $adapter->close($handle);

        $this->assertSame('abc', file_get_contents($path));
    }

    public function test_read_chunk_and_read_line(): void
    {
        $adapter = new FileSystemAdapter();
        $path = $this->path('read.txt');
        $adapter->ensureDirectory(dirname($path));
        file_put_contents($path, "abc\ndef");

        $handle = $adapter->openForRead($path);
        $this->assertSame('abc', $adapter->readChunk($handle, 3, $path, 'Failed to read file: %s'));
        $adapter->close($handle);

        $lineHandle = $adapter->openForRead($path);
        $this->assertSame("abc\n", $adapter->readLine($lineHandle, $path, 'Failed to read file: %s'));
        $adapter->close($lineHandle);
    }

    public function test_read_chunk_throws_on_non_readable_handle(): void
    {
        $adapter = new FileSystemAdapter();
        $path = $this->path('readonly.txt');
        $adapter->ensureDirectory(dirname($path));
        file_put_contents($path, 'x');

        $handle = fopen($path, 'rb');

        try {
            $adapter->write($handle, 'cannot-write');
            $this->fail('Expected exception was not thrown.');
        } catch (FileStorageException $exception) {
            $this->assertSame('Failed to write backup chunk to file', $exception->getMessage());
        } finally {
            fclose($handle);
        }
    }

    public function test_read_line_throws_when_stream_fails_without_eof(): void
    {
        $adapter = new FileSystemAdapter();
        $handle = $adapter->openForRead(self::FAIL_LINE_SCHEME . '://line');

        try {
            $adapter->readLine($handle, 'virtual-path', 'Failed to read encrypted file: %s');
            $this->fail('Expected exception was not thrown.');
        } catch (FileStorageException $exception) {
            $this->assertSame('Failed to read encrypted file: virtual-path', $exception->getMessage());
        } finally {
            $adapter->close($handle);
        }
    }

    public function test_rename_and_delete(): void
    {
        $adapter = new FileSystemAdapter();
        $adapter->ensureDirectory($this->baseDir);
        $from = $this->path('from.txt');
        $to = $this->path('to.txt');
        file_put_contents($from, 'data');

        $adapter->rename($from, $to);
        $this->assertFileExists($to);

        $adapter->delete($to);
        $this->assertFileDoesNotExist($to);

        $adapter->delete($to);
        $this->assertTrue(true);
    }

    private function path(string $suffix): string
    {
        return $this->baseDir . DIRECTORY_SEPARATOR . $suffix;
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

class FailingLineStream
{
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count)
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }
}
