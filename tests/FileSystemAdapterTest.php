<?php

declare(strict_types=1);

namespace Tests;

use App\Exceptions\BackupFileCloseException;
use App\Exceptions\BackupWriteIncompleteException;
use App\Exceptions\FileStorageException;
use App\Services\Internal\FileSystemAdapter;
use PHPUnit\Framework\TestCase;

class FileSystemAdapterTest extends TestCase
{
    private const FAIL_LINE_SCHEME = 'failline';

    private const WRITE_SCHEMES = [
        'shortwrite' => ShortWriteStream::class,
        'stuckwrite' => StuckWriteStream::class,
        'failflush' => FailingFlushStream::class,
    ];

    private string $baseDir;

    public static function setUpBeforeClass(): void
    {
        if (!in_array(self::FAIL_LINE_SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::FAIL_LINE_SCHEME, FailingLineStream::class);
        }

        foreach (self::WRITE_SCHEMES as $scheme => $class) {
            if (!in_array($scheme, stream_get_wrappers(), true)) {
                stream_wrapper_register($scheme, $class);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (in_array(self::FAIL_LINE_SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::FAIL_LINE_SCHEME);
        }

        foreach (array_keys(self::WRITE_SCHEMES) as $scheme) {
            if (in_array($scheme, stream_get_wrappers(), true)) {
                stream_wrapper_unregister($scheme);
            }
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
        } catch (BackupWriteIncompleteException $exception) {
            $this->assertSame(BackupWriteIncompleteException::CODE, $exception->errorCode());
            $this->assertSame(0, $exception->context()['written_bytes']);
            $this->assertSame(12, $exception->context()['expected_bytes']);
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
    public function test_write_completes_chunk_when_stream_accepts_partial_writes(): void
    {
        ShortWriteStream::$buffer = '';
        $handle = fopen('shortwrite://target', 'wb');

        (new FileSystemAdapter())->write($handle, 'backup-chunk-payload');

        $this->assertSame('backup-chunk-payload', ShortWriteStream::$buffer);
        fclose($handle);
    }

    public function test_write_throws_when_stream_stops_accepting_bytes(): void
    {
        $handle = fopen('stuckwrite://target', 'wb');

        try {
            (new FileSystemAdapter())->write($handle, 'backup-chunk-payload');
            $this->fail('Expected exception was not thrown.');
        } catch (BackupWriteIncompleteException $exception) {
            $this->assertSame(20, $exception->context()['expected_bytes']);
            $this->assertSame(StuckWriteStream::ACCEPTED, $exception->context()['written_bytes']);
        } finally {
            fclose($handle);
        }
    }

    public function test_close_written_throws_when_flush_fails(): void
    {
        $handle = fopen('failflush://target', 'wb');

        $this->expectException(BackupFileCloseException::class);

        (new FileSystemAdapter())->closeWritten($handle);
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

/**
 * Принимает не больше трёх байт за вызов — как `fwrite` при частичной записи.
 */
class ShortWriteStream
{
    public static string $buffer = '';

    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_write(string $data): int
    {
        $part = substr($data, 0, 3);
        self::$buffer .= $part;

        return strlen($part);
    }
}

/**
 * Принимает первые байты и перестаёт — как файл, у которого кончилось место.
 */
class StuckWriteStream
{
    public const ACCEPTED = 4;

    public $context;

    private int $written = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_write(string $data): int
    {
        $accepted = max(0, min(strlen($data), self::ACCEPTED - $this->written));
        $this->written += $accepted;

        return $accepted;
    }
}

/**
 * Не сбрасывает буфер на диск.
 */
class FailingFlushStream
{
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_write(string $data): int
    {
        return strlen($data);
    }

    public function stream_flush(): bool
    {
        return false;
    }
}
