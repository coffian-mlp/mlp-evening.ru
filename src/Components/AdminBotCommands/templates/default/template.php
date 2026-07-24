<?php
use Domain\Auth;
/**
 * @var array $arResult
 */
?>
<div style="display: flex; gap: 20px; align-items: flex-start;">
    
    <!-- Левая колонка: Форма -->
    <div style="flex: 1; max-width: 400px;">
        <div class="card" id="command-form-card">
            <h3 class="dashboard-title" id="command-form-title">➕ Добавить Команду</h3>
            <form action="/api.php" method="post" id="bot-command-form">
                <input type="hidden" name="action" value="save_bot_command">
                <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">
                <input type="hidden" name="id" id="command-id" value="0">
                
                <div class="form-group">
                    <label class="form-label">Команда (например /joke)</label>
                    <input type="text" name="command_prefix" id="command_prefix" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Краткое описание (для вас)</label>
                    <input type="text" name="description" id="command_description" class="form-input" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Тип обработчика</label>
                    <select name="handler_type" id="handler_type" class="form-input">
                        <option value="text">Текстовый промпт (Обычный)</option>
                        <option value="schedule">Расписание (Спец. логика)</option>
                        <option value="poll">Опрос (Спец. логика)</option>
                        <option value="todo">Беклог /todo (без LLM)</option>
                        <option value="image">Картинка /нарисуй (генерация)</option>
                        <option value="image_chat">Сценка чата /нарисуйчат (режиссёр+генерация)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Системный Промпт (Контекст)</label>
                    <textarea name="system_prompt" id="system_prompt" class="form-input" rows="8" placeholder="Инструкции боту для этой команды"></textarea>
                    <div style="font-size: 0.8em; color: #666; margin-top: 5px;">
                        В этом промпте боту объясняется, что он должен ответить. Если оставить пустым — будет стандартный ответ бота.
                    </div>
                </div>

                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" name="is_active" id="command_is_active" value="1" checked>
                        <span>Активна (включена)</span>
                    </label>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn-primary" style="flex: 1;">Сохранить</button>
                    <button type="button" class="btn-secondary" onclick="resetCommandForm()" id="cancel-edit-btn" style="display: none;">Отмена</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Правая колонка: Список -->
    <div style="flex: 2;">
        <div class="card">
            <h3 class="dashboard-title">🤖 Команды Бота</h3>
            
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th width="50">ID</th>
                        <th>Команда</th>
                        <th>Описание</th>
                        <th>Тип</th>
                        <th>Статус</th>
                        <th style="text-align: right;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($arResult['commands'])): ?>
                        <tr><td colspan="6" style="text-align:center;">Нет команд. Добавьте первую!</td></tr>
                    <?php else: ?>
                        <?php foreach ($arResult['commands'] as $cmd): ?>
                            <tr>
                                <td><?= $cmd['id'] ?></td>
                                <td style="font-family: monospace; color: #d63384; font-weight: bold;"><?= htmlspecialchars($cmd['command_prefix']) ?></td>
                                <td><?= htmlspecialchars($cmd['description']) ?></td>
                                <td>
                                    <?php if ($cmd['handler_type'] == 'schedule'): ?>
                                        <span style="background: #17a2b8; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em;">Schedule</span>
                                    <?php else: ?>
                                        <span style="background: #28a745; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em;">Text</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $cmd['is_active'] ? '<span style="color: green;">✔️</span>' : '<span style="color: red;">❌</span>' ?>
                                </td>
                                <td style="text-align: right;">
                                    <button class="btn-xs btn-warning" data-cmd="<?= htmlspecialchars(json_encode($cmd), ENT_QUOTES, 'UTF-8') ?>" onclick="editCommand(JSON.parse(this.dataset.cmd))">✏️</button>
                                    <form action="/api.php" method="post" class="bot-command-delete-form" style="display: inline-block;">
                                        <input type="hidden" name="action" value="delete_bot_command">
                                        <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">
                                        <input type="hidden" name="id" value="<?= $cmd['id'] ?>">
                                        <button type="submit" class="btn-xs btn-danger">🗑</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function editCommand(cmd) {
    document.getElementById('command-form-title').innerText = '✏️ Редактировать Команду';
    document.getElementById('command-id').value = cmd.id;
    document.getElementById('command_prefix').value = cmd.command_prefix;
    document.getElementById('command_description').value = cmd.description;
    document.getElementById('handler_type').value = cmd.handler_type;
    document.getElementById('system_prompt').value = cmd.system_prompt;
    document.getElementById('command_is_active').checked = cmd.is_active == 1;
    document.getElementById('cancel-edit-btn').style.display = 'block';
    
    // Плавный скролл к форме
    document.getElementById('command-form-card').scrollIntoView({ behavior: 'smooth' });
}

function resetCommandForm() {
    document.getElementById('command-form-title').innerText = '➕ Добавить Команду';
    document.getElementById('command-id').value = '0';
    document.getElementById('command_prefix').value = '';
    document.getElementById('command_description').value = '';
    document.getElementById('handler_type').value = 'text';
    document.getElementById('system_prompt').value = '';
    document.getElementById('command_is_active').checked = true;
    document.getElementById('cancel-edit-btn').style.display = 'none';
}
</script>

<!-- MLP-270: беклог фидбека из чата (команда /todo) -->
<div class="card" style="margin-top: 20px;">
    <h3 class="dashboard-title">📝 Беклог от пользователей (/todo)</h3>
    <div style="margin-bottom: 10px;">
        <select id="fb-status-filter" class="form-input" style="width: auto; display: inline-block;" onchange="loadFeedback()">
            <option value="new">Новые</option>
            <option value="">Все</option>
            <option value="done">Сделано</option>
            <option value="dismissed">Отклонено</option>
        </select>
        <span id="fb-count" style="margin-left: 10px; color: #888;"></span>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table" style="width: 100%;">
            <thead><tr><th>№</th><th>Когда</th><th>Кто</th><th>Текст</th><th>Статус</th><th></th></tr></thead>
            <tbody id="fb-rows"><tr><td colspan="6" style="color:#888;">Загрузка…</td></tr></tbody>
        </table>
    </div>
</div>

<script>
function loadFeedback() {
    const status = document.getElementById('fb-status-filter').value;
    $.post('/api.php', { action: 'get_feedback', status: status, limit: 100, csrf_token: window.csrfToken }, function (res) {
        if (!res.success) return;
        document.getElementById('fb-count').innerText = 'новых: ' + res.data.new_count + ', в выборке: ' + res.data.total;
        const rows = res.data.items.map(function (it) {
            const st = { 'new': '🆕', 'done': '✅', 'dismissed': '🚫' }[it.status] || it.status;
            const btns = it.status === 'new'
                ? '<button class="btn-small" onclick="setFeedbackStatus(' + it.id + ', \'done\')">✅</button> ' +
                  '<button class="btn-small" onclick="setFeedbackStatus(' + it.id + ', \'dismissed\')">🚫</button>'
                : '<button class="btn-small" onclick="setFeedbackStatus(' + it.id + ', \'new\')">↺</button>';
            const div = document.createElement('div');
            div.innerText = it.text; // экранирование пользовательского текста
            return '<tr><td>' + it.id + '</td><td>' + it.created_at + '</td><td>' + $('<i>').text(it.username).html() +
                   '</td><td style="max-width:480px; word-break:break-word;">' + div.innerHTML + '</td><td>' + st + '</td><td>' + btns + '</td></tr>';
        });
        document.getElementById('fb-rows').innerHTML = rows.length ? rows.join('') : '<tr><td colspan="6" style="color:#888;">Пусто. Тишина и покой.</td></tr>';
    }, 'json');
}

function setFeedbackStatus(id, status) {
    $.post('/api.php', { action: 'set_feedback_status', id: id, status: status, csrf_token: window.csrfToken }, function (res) {
        if (res.success) loadFeedback();
        else if (window.showFlashMessage) window.showFlashMessage(res.message, 'error');
    }, 'json');
}

loadFeedback();
</script>
