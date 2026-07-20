<?php

namespace Infra;

use Exception;


class Mailer {
    private $fromEmail;
    private $fromName;

    public function __construct() {
        // Можно вынести настройки в базу/конфиг, пока зададим дефолтные для проекта
        $this->fromEmail = 'noreply@mlp-evening.ru';
        $this->fromName = 'MLP Evening';
    }

    public function sendPasswordReset($toEmail, $resetLink) {
        $subject = "Восстановление пароля на MLP Evening";
        
        // Простой HTML шаблон
        $message = "
        <html>
        <head>
          <title>Восстановление пароля</title>
        </head>
        <body style='font-family: sans-serif; color: #333;'>
          <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
            <h2 style='color: #6d2f8e;'>🦄 Забыли пароль?</h2>
            <p>Ничего страшного, всякое бывает! Принцесса Луна тоже иногда забывает поднять Луну... (шутка).</p>
            <p>Чтобы задать новый пароль, просто нажми на кнопку ниже:</p>
            <p style='text-align: center;'>
              <a href='{$resetLink}' style='background-color: #6d2f8e; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Сбросить пароль</a>
            </p>
            <p>Или скопируй эту ссылку в браузер:</p>
            <p><small>{$resetLink}</small></p>
            <p>Ссылка действительна в течение 1 часа.</p>
            <hr>
            <p style='font-size: 0.8em; color: #999;'>Если это были не вы, просто проигнорируйте это письмо. Магия Гармонии защитит ваш аккаунт.</p>
          </div>
        </body>
        </html>
        ";

        return $this->send($toEmail, $subject, $message);
    }

    private function send($to, $subject, $htmlMessage) {
        // --- 1. ЛОГИРОВАНИЕ (как резерв) ---
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/mail.log';
        
        $logEntry = str_repeat("=", 50) . "\n";
        $logEntry .= "Date: " . date('Y-m-d H:i:s') . "\n";
        $logEntry .= "To: $to\n";
        $logEntry .= "Subject: $subject\n";
        $logEntry .= "Body:\n$htmlMessage\n";
        $logEntry .= str_repeat("=", 50) . "\n\n";

        // --- 2. ОТПРАВКА ---
        // $config = require __DIR__ . '/../config.php'; // Убираем чтение файла
        $configManager = ConfigManager::getInstance();
        
        // Сначала пишем в лог (для истории)
        if (file_put_contents($logFile, $logEntry, FILE_APPEND) === false) {
             error_log("Mailer Error: Cannot write to $logFile");
             // Не падаем
        }

        // Проверяем: Включен ли SMTP в базе?
        if ($configManager->getOption('smtp_enabled', 0)) {
            
            try {
                $smtp = new SimpleSMTP(
                    $configManager->getOption('smtp_host'),
                    $configManager->getOption('smtp_port', 465),
                    $configManager->getOption('smtp_user'),
                    $configManager->getOption('smtp_pass')
                );

                $headers = [
                    "MIME-Version: 1.0",
                    "Content-type: text/html; charset=UTF-8",
                    "X-Mailer: MLP-Evening SMTP"
                ];

                return $smtp->send($to, $subject, $htmlMessage, $headers);
                
            } catch (Exception $e) {
                // Если SMTP упал
                $errorMsg = "SMTP Send Error: " . $e->getMessage();
                error_log($errorMsg);
                file_put_contents($logDir . '/mail_errors.log', date('Y-m-d H:i:s') . " - " . $errorMsg . "\n", FILE_APPEND);
                
                throw new Exception("Ошибка отправки через SMTP. Проверьте настройки.");
            }
        } 
        
        // Если SMTP выключен - используем стандартный mail()
        // (На локалке он, скорее всего, не сработает, но для продакшена ок)
        /*
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($this->fromName) . "?= <" . $this->fromEmail . ">\r\n";
        return mail($to, $subject, $htmlMessage, $headers);
        */
        
        // В рамках текущей задачи мы считаем, что если SMTP выключен - мы успешно "отправили" в лог.
        return true;
    }
}
