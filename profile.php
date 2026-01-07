<?php
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/UserManager.php';

Auth::requireLogin();

$userManager = new UserManager();
$user = $userManager->getUserById($_SESSION['user_id']);

if (!$user) {
    echo "Ошибка загрузки профиля";
    exit();
}

$pageTitle = 'Мой Профиль - MLP Evening';
$bodyClass = 'dashboard-layout'; // Используем тот же лейаут (с фоном)
$extraCss = '<link rel="stylesheet" href="/assets/css/dashboard.css">'; // Стили форм оттуда подходят
$extraScripts = '<script src="/assets/js/dashboard.js"></script>'; // Для AJAX обработки форм

require_once __DIR__ . '/src/templates/header.php';
?>

<div class="container" style="max-width: 800px; margin-top: 40px;">
    
    <div style="margin-bottom: 20px;">
        <a href="/" class="btn-return" style="margin-top: 0;">&larr; На главную</a>
        <?php if (Auth::isAdmin()): ?>
            <a href="/dashboard.php" class="btn-warning" style="float: right;">🔧 Админка</a>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 class="dashboard-title">🦄 Твой профиль</h2>
        
        <form id="profile-form" action="api.php" method="post">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">
            
            <div class="form-group">
                <label class="form-label">Логин</label>
                <input type="text" name="login" value="<?= htmlspecialchars($user['login']) ?>" class="form-input" required minlength="3">
                <small style="color: #777;">Твое имя для входа.</small>
            </div>

            <div class="form-group">
                <label class="form-label">Имя в чате</label>
                <input type="text" name="nickname" value="<?= htmlspecialchars($user['nickname']) ?>" class="form-input" required>
                <small style="color: #777;">Это имя увидят все.</small>
            </div>

            <div class="form-group">
                <label class="form-label">Цвет имени</label>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <input type="color" name="chat_color" value="<?= htmlspecialchars($user['chat_color'] ?? '#6d2f8e') ?>" style="height: 40px; width: 60px; padding: 0; border: none; cursor: pointer;">
                    <span style="color: #666;">Выбери свой любимый цвет!</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Аватарка</label>
                <input type="text" name="avatar_url" value="<?= htmlspecialchars($user['avatar_url'] ?? '') ?>" class="form-input" placeholder="https://example.com/my-avatar.png">
                <small style="color: #777;">Вставь ссылку на картинку.</small>
            </div>

            <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">

            <div class="form-group">
                <label class="form-label">Новый пароль</label>
                <input type="password" name="password" class="form-input" placeholder="Оставь пустым, если не меняешь">
            </div>

            <button type="submit" class="btn-primary">💾 Сохранить</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/src/templates/footer.php'; ?>

