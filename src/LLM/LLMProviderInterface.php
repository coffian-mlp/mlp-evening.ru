<?php

interface LLMProviderInterface {
    /**
     * Отправляет запрос к LLM и возвращает ответ.
     *
     * @param array $messagesContext Массив сообщений в формате [['role' => 'user|assistant', 'content' => '...']]
     * @param string $systemPrompt Системный промпт, определяющий характер бота
     * @return string|null Ответ от LLM или null в случае ошибки
     * @throws Exception Если провайдер недоступен
     */
    public function askChat(array $messagesContext, string $systemPrompt): ?string;
}
