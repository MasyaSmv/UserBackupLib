<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class FileStorageService
{
    /**
     * Расшифровывает файл с данными пользователя.
     *
     * Поддерживает два формата:
     *  1) Старый: целиком зашифрованная JSON-строка (одна строка в файле).
     *  2) Новый: файл состоит из нескольких строк, каждая строка — шифротекст отдельного чанка JSON.
     *
     * @param string $encryptedFilePath
     *
     * @return array
     * @throws RuntimeException
     */
    public static function decryptFile(string $encryptedFilePath): array
    {
        if (!file_exists($encryptedFilePath)) {
            throw new RuntimeException("Encrypted file not found: $encryptedFilePath");
        }

        $rawContent = file_get_contents($encryptedFilePath);

        if ($rawContent === false) {
            throw new RuntimeException("Failed to read file: $encryptedFilePath");
        }

        // Если расширение .enc — считаем, что внутри зашифрованные данные
        if (pathinfo($encryptedFilePath, PATHINFO_EXTENSION) === 'enc') {
            // Определяем формат: старый (одна строка) или новый (много строк-чанков)
            if (!str_contains($rawContent, "\n") && !str_contains($rawContent, "\r")) {
                // Старый формат: один шифротекст целиком
                $jsonData = Crypt::decryptString($rawContent);
            } else {
                // Новый формат: каждый чанк — отдельная строка с шифротекстом
                $lines = preg_split('/\r\n|\n|\r/', trim($rawContent));
                $jsonBuffer = '';

                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $jsonBuffer .= Crypt::decryptString($line);
                }

                $jsonData = $jsonBuffer;
            }
        } else {
            // Незашифрованный JSON
            $jsonData = $rawContent;
        }

        $decoded = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                sprintf(
                    'Failed to decode JSON from backup file "%s": %s',
                    $encryptedFilePath,
                    json_last_error_msg(),
                ),
            );
        }

        return $decoded;
    }

    /**
     * Сохраняет данные пользователя в файл и возвращает путь до сохраненного файла.
     *
     * При $encrypt = true:
     *  - JSON режется на чанки фиксированного размера;
     *  - каждый чанк шифруется отдельно;
     *  - зашифрованные чанки пишутся в файл построчно.
     *
     * Это позволяет избежать пикового потребления памяти при шифровании
     * огромного JSON'а одной строкой.
     *
     * @param string $filePath Путь до файла БЕЗ расширения .enc
     * @param array $data Данные для сохранения
     * @param bool $encrypt Шифровать ли данные
     *
     * @return string Итоговый путь до файла (с учётом .enc для зашифрованных)
     */
    public function saveToFile(string $filePath, array $data, bool $encrypt = true): string
    {
        // Преобразуем данные в JSON.
        // JSON_UNESCAPED_UNICODE — чтобы не раздувать строку \uXXXX-последовательностями.
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

        if ($jsonData === false) {
            throw new RuntimeException('Failed to encode backup data to JSON: ' . json_last_error_msg());
        }

        if ($encrypt) {
            // Для зашифрованного файла добавляем .enc
            $filePath .= '.enc';

            $directoryPath = dirname($filePath);
            if (!is_dir($directoryPath) && !mkdir($directoryPath, 0755, true) && !is_dir($directoryPath)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $directoryPath));
            }

            $handle = fopen($filePath, 'wb');
            if (!$handle) {
                throw new RuntimeException("Unable to open file for writing: {$filePath}");
            }

            try {
                $length = strlen($jsonData);
                $offset = 0;
                $chunkSize = 5 * 1024 * 1024; // 5 MB на один чанк

                while ($offset < $length) {
                    // Вырезаем кусок исходного JSON
                    $chunk = substr($jsonData, $offset, $chunkSize);

                    // Шифруем только этот чанк
                    $encryptedChunk = Crypt::encryptString($chunk);

                    // Каждую зашифрованную строку пишем с переводом строки
                    fwrite($handle, $encryptedChunk . PHP_EOL);

                    $offset += $chunkSize;
                }
            } finally {
                fclose($handle);
            }
        } else {
            // Без шифрования — просто пишем JSON целиком
            $directoryPath = dirname($filePath);
            if (!is_dir($directoryPath) && !mkdir($directoryPath, 0755, true) && !is_dir($directoryPath)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $directoryPath));
            }

            file_put_contents($filePath, $jsonData);
        }

        return $filePath;
    }
}
