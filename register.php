<?php
require_once __DIR__ . '/src/Auth.php';

// Если уже залогинен - редирект
if (Auth::check()) {
    header("Location: /");
    exit();
}

$pageTitle = 'Регистрация - MLP Evening';
$bodyClass = 'dashboard-layout';
// Используем те же стили, что и для входа
$extraCss = '<link rel="stylesheet" href="/assets/css/login.css">';

require_once __DIR__ . '/src/templates/header.php';
?>

<div class="login-wrapper">
    <div class="login-box" style="max-width: 450px;">
        <h2 class="login-title">✨ Новая запись в библиотеке</h2>
        
        <form id="register-form" action="api.php" method="post">
            <input type="hidden" name="action" value="register">
            
            <div class="form-group">
                <label for="login" class="form-label">Логин (для входа)*</label>
                <input type="text" id="login" name="login" class="form-input" required minlength="3" placeholder="Только латиница и цифры">
            </div>

            <div class="form-group">
                <label for="nickname" class="form-label">Никнейм (для чата)</label>
                <input type="text" id="nickname" name="nickname" class="form-input" placeholder="Как вас звать?">
                <small style="color: #777;">Можно оставить пустым, тогда будет как логин.</small>
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">Пароль*</label>
                <input type="password" id="password" name="password" class="form-input" required minlength="6">
            </div>

            <div class="form-group">
                <label for="password_confirm" class="form-label">Повторите пароль*</label>
                <input type="password" id="password_confirm" name="password_confirm" class="form-input" required>
            </div>

            <div class="form-group">
                <label for="captcha" class="form-label">Проверка на пони: Как зовут дракончика-помощника?*</label>
                <input type="text" id="captcha" name="captcha" class="form-input" required placeholder="Имя на русском или английском">
            </div>
            
            <button type="submit" class="btn-submit">Зарегистрироваться</button>
        </form>
        
        <div class="back-link-wrapper" style="display: flex; justify-content: space-between; align-items: center;">
            <a href="/" class="back-link">&larr; На главную</a>
            <a href="/login.php" class="back-link" style="font-weight: bold;">Уже есть аккаунт?</a>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#register-form').on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var pass = $('#password').val();
        var passConf = $('#password_confirm').val();
        
        if (pass !== passConf) {
            window.showFlashMessage('Пароли не совпадают!', 'error');
            return;
        }

        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Магия творится...');
        
        $.ajax({
            url: $form.attr('action'),
            type: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    window.showFlashMessage(res.message, 'success');
                    // Редирект через секунду
                    setTimeout(function() {
                        window.location.href = '/';
                    }, 1000);
                } else {
                    window.showFlashMessage(res.message, 'error');
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function(xhr, status, error) {
                window.showFlashMessage('Ошибка соединения: ' + error, 'error');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>

