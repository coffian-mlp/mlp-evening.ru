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
                                    <?php
                                    // MLP-288 (AR7-L8): бейдж на каждый handler_type — раньше
                                    // poll/todo/image/image_chat показывались как «Text».
                                    $badges = [
                                        'schedule'   => ['Schedule', '#17a2b8'],
                                        'poll'       => ['Poll',     '#6f42c1'],
                                        'todo'       => ['Todo',     '#fd7e14'],
                                        'image'      => ['Art',      '#d63384'],
                                        'image_chat' => ['ArtChat',  '#a83279'],
                                        'text'       => ['Text',     '#28a745'],
                                    ];
                                    [$badgeLabel, $badgeColor] = $badges[$cmd['handler_type']] ?? ['Text', '#28a745'];
                                    ?>
                                    <span style="background: <?= $badgeColor ?>; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.8em;"><?= $badgeLabel ?></span>
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

<!-- MLP-288: JS вкладки — в script.js шаблона (автоподключение Component) -->

<!-- MLP-270: беклог фидбека из чата (команда /todo) -->
<div class="card" style="margin-top: 20px;">
    <h3 class="dashboard-title">📝 Беклог от пользователей (/todo)</h3>
    <!-- MLP-291: селект БЕЗ inline display — иначе он перебивает display:none
         из .custom-select-wrapper и рядом с кастомным виджетом виден второй,
         нативный селект. Компактность — шириной обёртки. -->
    <div style="margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
        <div style="width: 220px;">
            <select id="fb-status-filter" class="form-input" onchange="loadFeedback()">
                <option value="new">Новые</option>
                <option value="">Все</option>
                <option value="done">Сделано</option>
                <option value="dismissed">Отклонено</option>
            </select>
        </div>
        <span id="fb-count" style="color: #888;"></span>
    </div>
    <div style="overflow-x: auto;">
        <table class="admin-table" style="width: 100%;">
            <thead><tr><th>№</th><th>Когда</th><th>Кто</th><th>Текст</th><th>Статус</th><th></th></tr></thead>
            <tbody id="fb-rows"><tr><td colspan="6" style="color:#888;">Загрузка…</td></tr></tbody>
        </table>
    </div>
</div>

<!-- MLP-280: метрики Лиры (загрузка — script.js, loadLyraMetrics) -->
<div class="card" style="margin-top: 20px;">
    <h3 class="dashboard-title">📊 Метрики Лиры (7 дней)</h3>
    <div id="lyra-metrics" style="color:#888;">Загрузка…</div>
</div>
