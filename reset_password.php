<?php
require_once __DIR__ . '/autoload.php'; // MLP-248

$pageTitle = 'Восстановление пароля - MLP-evening.ru';

// Проверяем токен
$token = $_GET['token'] ?? '';
$isValid = false;
$errorMsg = '';

if (empty($token)) {
    $errorMsg = "Ссылка недействительна.";
} else {
    $userManager = new UserManager();
    $tokenHash = hash('sha256', $token);
    $user = $userManager->getUserByResetToken($tokenHash);
    
    if ($user) {
        $isValid = true;
    } else {
        $errorMsg = "Ссылка устарела или недействительна.";
    }
}

// Генерация CSRF токена
$csrfToken = Auth::generateCsrfToken();

// Minimal header
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="icon" href="/favicon.png">
    <link rel="stylesheet" href="/assets/css/fonts.css">
    <link rel="stylesheet" href="/assets/css/main.css">
    <script src="/assets/js/jquery.min.js"></script>
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: url(/assets/img/bg.jpg) no-repeat center center fixed #2B1F43;
            background-size: cover;
        }
        .reset-box {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        h2 { color: #6d2f8e; margin-top: 0; }
        .btn-primary { width: 100%; margin-top: 10px; }
        .error-msg { color: red; margin-top: 10px; font-size: 0.9em; }
        .success-msg { color: green; margin-top: 10px; font-size: 0.9em; }
    </style>
</head>
<body>

<div class="reset-box">
    <?php if ($isValid): ?>
        <h2>🦄 Новый пароль</h2>
        <p>Привет, <b><?= htmlspecialchars($user['nickname'] ?? $user['login']) ?></b>!<br>Придумай новый пароль.</p>
        
        <form id="reset-form">
            <input type="hidden" name="action" value="reset_password_submit">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            
            <div class="form-group">
                <div class="password-wrapper">
                    <input type="password" name="password" id="pass1" class="form-input" placeholder="Новый пароль (мин. 6)" required minlength="6">
                    <button type="button" class="password-toggle-btn">👁️</button>
                </div>
            </div>
            <div class="form-group" style="margin-top: 15px;">
                <div class="password-wrapper">
                    <input type="password" name="password_confirm" id="pass2" class="form-input" placeholder="Повторите пароль" required>
                    <button type="button" class="password-toggle-btn">👁️</button>
                </div>
            </div>
            
            <button type="submit" class="btn-primary">Сохранить</button>
            <div id="form-msg" style="margin-top: 10px;"></div>
        </form>
    <?php else: ?>
        <h2 style="color: #c0392b;">💔 Ошибка</h2>
        <p><?= htmlspecialchars($errorMsg) ?></p>
        <a href="/" class="btn-primary">Вернуться на главную</a>
    <?php endif; ?>
</div>

<script>
$(document).ready(function() {
    // Password Toggle Logic
    $(document).on('click', '.password-toggle-btn', function(e) {
        e.preventDefault();
        const btn = $(this);
        const input = btn.siblings('input');
        
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            btn.text('🙈');
        } else {
            input.attr('type', 'password');
            btn.text('👁️');
        }
    });

    $('#reset-form').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button');
        const msg = $('#form-msg');
        
        const p1 = $('#pass1').val();
        const p2 = $('#pass2').val();

        // Очистка сообщений
        msg.text('').removeClass('error-msg success-msg');

        // Валидация
        if (p1.length < 6) {
            msg.addClass('error-msg').text('Пароль слишком короткий (нужно минимум 6 символов)');
            return;
        }
        if (p1 !== p2) {
            msg.addClass('error-msg').text('Пароли не совпадают! Проверьте ввод.');
            return;
        }
        
        btn.prop('disabled', true).text('Сохранение...');
        
        $.post('/api.php', $(this).serialize(), function(res) {
            btn.prop('disabled', false).text('Сохранить');
            if (res.success) {
                msg.addClass('success-msg').text(res.message);
                setTimeout(function() {
                    window.location.href = res.data.redirect || '/';
                }, 2000);
            } else {
                msg.addClass('error-msg').text(res.message);
            }
        }, 'json').fail(function() {
            btn.prop('disabled', false).text('Сохранить');
            msg.addClass('error-msg').text('Ошибка сети');
        });
    });
});
</script>

</body>
</html>
