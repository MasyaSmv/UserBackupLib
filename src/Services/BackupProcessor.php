<?php

namespace App\Services;

class BackupProcessor
{
    protected array $userData = [];

    /**
     * Добавляет данные пользователя к существующему набору данных.
     *
     * @param string $table
     * @param array $data
     */
    public function appendUserData(string $table, array $data): void
    {
        if (!empty($data)) {
            if (!isset($this->userData[$table])) {
                $this->userData[$table] = [];
            }

            foreach ($data as $row) {
                $this->userData[$table][] = $row;
            }
        }
    }

    /**
     * Возвращает все собранные данные пользователя.
     *
     * @return array
     */
    public function getUserData(): array
    {
        return $this->userData;
    }

    /**
     * Очищает данные пользователя.
     */
    public function clearUserData(): void
    {
        $this->userData = [];
    }
}
