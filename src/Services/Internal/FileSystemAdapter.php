<?php

declare(strict_types=1);

namespace App\Services\Internal;

use App\Exceptions\FileStorageException;

class FileSystemAdapter
{
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function ensureDirectory(string $directoryPath): void
    {
        if ($this->isDirectory($directoryPath)) {
            return;
        }

        if (@mkdir($directoryPath, 0755, true)) {
            return;
        }

        if ($this->isDirectory($directoryPath)) {
            return;
        }

        throw new FileStorageException(sprintf('Directory "%s" was not created', $directoryPath));
    }

    /**
     * @return resource
     */
    public function openForWrite(string $path)
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            throw new FileStorageException("Unable to open temp file for writing: {$path}");
        }

        return $handle;
    }

    /**
     * @return resource
     */
    public function openForRead(string $path, bool $encrypted = false)
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            $message = $encrypted
                ? "Failed to open encrypted file: {$path}"
                : "Failed to open file: {$path}";

            throw new FileStorageException($message);
        }

        return $handle;
    }

    public function write($handle, string $chunk): void
    {
        if (@fwrite($handle, $chunk) === false) {
            throw new FileStorageException('Failed to write backup chunk to file');
        }
    }

    public function readChunk($handle, int $chunkSize, string $path, string $errorMessage): string
    {
        $chunk = @fread($handle, $chunkSize);

        if ($chunk === false) {
            throw new FileStorageException(sprintf($errorMessage, $path));
        }

        return $chunk;
    }

    public function readLine($handle, string $path, string $errorMessage): ?string
    {
        $line = @fgets($handle);

        if ($line === false) {
            if (@feof($handle)) {
                return null;
            }

            throw new FileStorageException(sprintf($errorMessage, $path));
        }

        return $line;
    }

    public function close($handle): void
    {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    public function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new FileStorageException("Unable to move temp file to {$to}");
        }
    }

    public function delete(string $path): void
    {
        if ($this->exists($path)) {
            @unlink($path);
        }
    }
}
