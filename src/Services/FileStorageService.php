<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FileStorageService
{
    /**
     * Сохраняет данные пользователя в файл и возвращает путь до сохраненного файла.
     *
     * @param string $filePath
     * @param array $data
     * @param bool $encrypt
     * @return string
     */
    public function saveToFile(string $filePath, array $data, bool $encrypt = true): string
    {
        // Сохраняем данные в файл
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));

        // Шифруем файл, если это указано
        if ($encrypt) {
            $this->encryptFile($filePath);
            $filePath .= '.enc'; // Обновляем путь до зашифрованного файла
        }

        // Возвращаем путь до сохраненного файла
        return $filePath;
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

        // Удаляем оригинальный файл после шифрования
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Расшифровывает файл с данными пользователя.
     *
     * @param string $encryptedFilePath
     * @param string $decryptedFilePath
     * @return array
     * @throws RuntimeException
     */
    public function decryptFile(string $encryptedFilePath, string $decryptedFilePath): array
    {
        if (!file_exists($encryptedFilePath)) {
            throw new RuntimeException("Encrypted file not found: $encryptedFilePath");
        }

        $password = env('BACKUP_ENCRYPTION_KEY', 'your-secret-password');

        $command = "openssl enc -aes-256-cbc -d -in $encryptedFilePath -out $decryptedFilePath -k $password";
        shell_exec($command);

        $jsonData = file_get_contents($decryptedFilePath);

        // Удаляем временный расшифрованный файл
        if (file_exists($decryptedFilePath)) {
            unlink($decryptedFilePath);
        }

        return json_decode($jsonData, true);
    }
}
