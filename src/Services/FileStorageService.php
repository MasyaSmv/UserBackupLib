<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FileStorageService
{
    /**
     * Сохраняет данные пользователя в файл.
     *
     * @param string $filePath
     * @param array $data
     * @param bool $encrypt
     */
    public function saveToFile(string $filePath, array $data, bool $encrypt = true): void
    {
        Storage::put($filePath, json_encode($data, JSON_PRETTY_PRINT));

        if ($encrypt) {
            $this->encryptFile($filePath);
        }
    }

    /**
     * Шифрует файл с данными пользователя.
     *
     * @param string $filePath
     */
    protected function encryptFile(string $filePath): void
    {
        $outputPath = $filePath . '.enc';
        $password = env('BACKUP_ENCRYPTION_KEY', 'your-secret-password');

        $command = "openssl enc -aes-256-cbc -salt -in $filePath -out $outputPath -k $password";
        shell_exec($command);

        Storage::delete($filePath);
    }

    /**
     * Расшифровывает файл с данными пользователя.
     *
     * @param string $encryptedFilePath
     * @param string $decryptedFilePath
     * @return array
     * @throws Exception
     */
    public function decryptFile(string $encryptedFilePath, string $decryptedFilePath): array
    {
        if (!Storage::exists($encryptedFilePath)) {
            throw new RuntimeException("Encrypted file not found: $encryptedFilePath");
        }

        $password = env('BACKUP_ENCRYPTION_KEY', 'your-secret-password');

        $command = "openssl enc -aes-256-cbc -d -in $encryptedFilePath -out $decryptedFilePath -k $password";
        shell_exec($command);

        $jsonData = Storage::get($decryptedFilePath);
        Storage::delete($decryptedFilePath); // Удаляем временный расшифрованный файл

        return json_decode($jsonData, true);
    }
}
