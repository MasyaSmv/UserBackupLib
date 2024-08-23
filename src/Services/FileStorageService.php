<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
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
        // Преобразуем данные в JSON
        $jsonData = json_encode($data, JSON_PRETTY_PRINT);

        // Шифруем данные, если это указано
        if ($encrypt) {
            $jsonData = Crypt::encryptString($jsonData);
            $filePath .= '.enc'; // Обновляем путь до зашифрованного файла
        }

        // Сохраняем данные (зашифрованные или нет) в файл
        file_put_contents($filePath, $jsonData);

        return $filePath;
    }

    /**
     * Расшифровывает файл с данными пользователя.
     *
     * @param string $encryptedFilePath
     * @return array
     * @throws RuntimeException
     */
    public static function decryptFile(string $encryptedFilePath): array
    {
        if (!file_exists($encryptedFilePath)) {
            throw new RuntimeException("Encrypted file not found: $encryptedFilePath");
        }

        $jsonData = file_get_contents($encryptedFilePath);

        // Расшифровываем, если файл зашифрован
        if (pathinfo($encryptedFilePath, PATHINFO_EXTENSION) === 'enc') {
            $jsonData = Crypt::decryptString($jsonData);
        }

        return json_decode($jsonData, true);
    }
}
