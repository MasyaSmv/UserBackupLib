<?php

declare(strict_types=1);

namespace App\Services\Internal;

use App\Exceptions\FileStorageException;
use Generator;
use Illuminate\Support\Facades\Crypt;

class BackupChunkReader
{
    public function __construct(
        private ?FileSystemAdapter $fileSystem = null
    ) {
    }

    public function iterateDecryptedChunks(string $filePath, int $chunkSize = 1048576): Generator
    {
        $fileSystem = $this->fileSystem();

        if (!$fileSystem->exists($filePath)) {
            throw new FileStorageException("Encrypted file not found: $filePath");
        }

        if (pathinfo($filePath, PATHINFO_EXTENSION) !== 'enc') {
            $handle = $fileSystem->openForRead($filePath);

            try {
                while (!feof($handle)) {
                    $chunk = $fileSystem->readChunk($handle, $chunkSize, $filePath, 'Failed to read file: %s');

                    if ($chunk === '') {
                        continue;
                    }

                    yield $chunk;
                }
            } finally {
                $fileSystem->close($handle);
            }

            return;
        }

        $handle = $fileSystem->openForRead($filePath, true);

        try {
            while (($line = $fileSystem->readLine($handle, $filePath, 'Failed to read encrypted file: %s')) !== null) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                yield Crypt::decryptString($line);
            }
        } finally {
            $fileSystem->close($handle);
        }
    }

    private function fileSystem(): FileSystemAdapter
    {
        return $this->fileSystem ??= new FileSystemAdapter();
    }
}
