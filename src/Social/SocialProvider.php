<?php

interface SocialProvider {
    /**
     * Возвращает уникальный идентификатор провайдера (telegram, discord, vk)
     */
    public function getName(): string;

    /**
     * Проверяет данные, пришедшие от провайдера (валидация подписи и т.д.)
     * @param array $data Данные из $_GET или $_POST
     * @return array|null Возвращает нормализованный массив данных пользователя или null в случае ошибки
     * Structure: ['id' => '...', 'first_name' => '...', 'username' => '...', 'photo_url' => '...']
     */
    public function validateCallback(array $data): ?array;
}
