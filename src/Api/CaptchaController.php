<?php

namespace Api;

use Domain\CaptchaManager;

/**
 * API-обработчики «Испытания Гармонии» (MLP-245) — срез из легаси-цепочки api.php
 * в тонкий роутер. Ответы — глобальной sendResponse() (api.php), роль — роутер.
 */
class CaptchaController {

    /** Начать испытание: выдать первый вопрос. */
    public static function start(): void {
        $captcha = new CaptchaManager();
        $data = $captcha->start();
        sendResponse(true, "Капча начата", 'success', $data);
    }

    /** Проверить ответ шага. */
    public static function check(): void {
        $captcha = new CaptchaManager();
        $answer = $_POST['answer'] ?? '';
        $result = $captcha->checkAnswer($answer);

        if ($result['success']) {
            sendResponse(true, "Верно!", 'success', $result);
        } else {
            sendResponse(false, $result['message'], 'error');
        }
    }
}
