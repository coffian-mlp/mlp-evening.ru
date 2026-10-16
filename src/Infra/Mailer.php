<?php

namespace Infra;

use Exception;


class Mailer {
    public const DEFAULT_FROM_EMAIL = 'noreply@mlp-evening.ru';

    /** Remove only attributable legacy recovery copies, without clearing unrelated history. */
    public static function eraseRecipientLogs(string $email): void {
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))return;
        self::eraseLogRecipient(dirname(__DIR__,2).'/logs/mail.log',$email,true);
        self::eraseLogRecipient(dirname(__DIR__,2).'/logs/mail_errors.log',$email,false);
    }
    private static function eraseLogRecipient(string $path,string $email,bool $blocks): void {
        if(!is_file($path))return;
        $file=fopen($path,'c+');if(!$file||!flock($file,LOCK_EX))throw new \RuntimeException('Cannot lock recovery log');
        try {
            $old=stream_get_contents($file);
            $new=$blocks?preg_replace_callback('/^={50}\RDate: [^\r\n]+\R.*?^={50}\R\R?/ms',static function($match)use($email){
                return preg_match('/^To: ([^\r\n]+)$/m',$match[0],$to)&&strcasecmp(trim($to[1]),$email)===0?'':$match[0];
            },$old):implode('',array_filter(preg_split('/(?<=\n)/',$old),static fn($line)=>stripos($line,$email)===false));
            if($new===null)throw new \RuntimeException('Cannot parse recovery log');
            if($new!==$old){rewind($file);if(fwrite($file,$new)!==strlen($new)||!ftruncate($file,strlen($new)))throw new \RuntimeException('Cannot rewrite recovery log');}
        } finally {flock($file,LOCK_UN);fclose($file);}
    }
    private $fromEmail;
    private $fromName;

    public function __construct() {
        // Можно вынести настройки в базу/конфиг, пока зададим дефолтные для проекта
        $this->fromEmail = self::DEFAULT_FROM_EMAIL;
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
        $logEntry .= "Subject: $subject\n";
        $logEntry .= "Recipient and body omitted: privacy policy\n";
        $logEntry .= str_repeat("=", 50) . "\n\n";

        // --- 2. ОТПРАВКА ---
        // $config = require __DIR__ . '/../config.php'; // Убираем чтение файла
        $configManager = ConfigManager::getInstance();
        
        // Сначала пишем в лог (для истории)
        if (file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
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
                $errorMsg = "SMTP Send Error: " . get_class($e) . " (code " . $e->getCode() . ")";
                error_log($errorMsg);
                file_put_contents($logDir . '/mail_errors.log', date('Y-m-d H:i:s') . " - " . $errorMsg . "\n", FILE_APPEND | LOCK_EX);
                
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
