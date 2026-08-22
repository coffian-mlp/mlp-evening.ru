// Вкладка «Бот» дашборда (MLP-288, AR7-10): логика формы команд, беклога /todo
// и метрик Лиры — вынесена из трёх inline-блоков template.php.
// Функции глобальные: их зовут inline-атрибуты onclick/onchange в разметке.

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

function loadLyraMetrics() {
    $.post('/api.php', { action: 'get_lyra_metrics', csrf_token: window.csrfToken }, function (res) {
        if (!res.success) { document.getElementById('lyra-metrics').innerText = res.message; return; }
        const d = res.data;
        const typeNames = { mention: 'Ответы (упоминания)', greeting: 'Приветствия', dynamic_command: 'Команды', cron_spontaneous: 'Проактив' };
        let rows = '';
        Object.keys(d.jobs.by_type).forEach(function (t) {
            const st = d.jobs.by_type[t];
            const total = Object.values(st).reduce((a, b) => a + b, 0);
            const failed = st.failed || 0;
            rows += '<tr><td>' + (typeNames[t] || t) + '</td><td>' + total + '</td><td>' + (st.done || 0) + '</td>' +
                    '<td' + (failed ? ' style="color:#e57373;"' : '') + '>' + failed + '</td></tr>';
        });
        const days = Object.keys(d.jobs.by_day).map(k => k.slice(5) + ': <b>' + d.jobs.by_day[k] + '</b>').join(' · ');
        const hb = d.worker.heartbeat_age === null ? '—' : (d.worker.heartbeat_age < 90 ? '✅ жив (' + d.worker.heartbeat_age + 'с)' : '⚠️ молчит ' + Math.round(d.worker.heartbeat_age / 60) + ' мин');
        document.getElementById('lyra-metrics').innerHTML =
            '<div style="display:flex; gap:24px; flex-wrap:wrap; margin-bottom:12px;">' +
            '<div>🫀 Воркер: ' + hb + ' <span style="color:#888;">(' + d.worker.mode + ')</span></div>' +
            '<div>🎨 Рисунки: сегодня <b>' + d.images.today + '</b>/' + d.images.daily_limit + ', всего <b>' + d.images.total_files + '</b></div>' +
            '<div>📝 Беклог: 🆕 <b>' + d.feedback.new + '</b> · ✅ ' + d.feedback.done + ' · 🚫 ' + d.feedback.dismissed + '</div>' +
            '</div>' +
            '<div style="overflow-x:auto;"><table class="admin-table" style="width:100%;">' +
            '<thead><tr><th>Задачи бота</th><th>Всего</th><th>Done</th><th>Failed</th></tr></thead>' +
            '<tbody>' + (rows || '<tr><td colspan="4" style="color:#888;">Журнал пуст</td></tr>') + '</tbody></table></div>' +
            '<p style="font-size:0.85em; color:#888; margin-top:8px;">По дням: ' + (days || '—') + '</p>';
    }, 'json');
}

// MLP-314: карточка «Память Лиры» — список/правка/удаление записей bot_memory.
function loadMemory() {
    const kind = document.getElementById('mem-kind-filter').value;
    $.post('/api.php', { action: 'get_memory', kind: kind, limit: 200, csrf_token: window.csrfToken }, function (res) {
        if (!res.success) return;
        document.getElementById('mem-count').innerText = 'в выборке: ' + res.data.total;
        const rows = res.data.items.map(function (it) {
            const kindIcon = it.kind === 'dossier' ? '👤' : '🎭';
            // Владелец досье: null при user_id>0 = пользователь удалён (досье-сирота).
            const owner = it.kind === 'dossier'
                ? (it.owner_nick !== null ? $('<i>').text('@' + it.owner_nick).html() : '<span style="color:#e57373;">пользователь удалён</span>')
                : '—';
            const div = document.createElement('div');
            div.innerText = it.text; // экранирование пользовательского текста
            const src = it.source === 'auto' ? '🤖 auto' : '✍️ manual';
            const btns = '<button class="btn-small" onclick="editMemoryRow(' + it.id + ')">✏️</button> ' +
                         '<button class="btn-small" onclick="deleteMemoryRow(' + it.id + ')">🗑️</button>';
            return '<tr data-mem-id="' + it.id + '"><td>' + it.id + '</td><td>' + kindIcon + '</td><td>' + owner +
                   '</td><td class="mem-text" style="max-width:480px; word-break:break-word;">' + div.innerHTML +
                   '</td><td>' + src + '</td><td>' + (it.created_at || '') + '</td><td>' + btns + '</td></tr>';
        });
        document.getElementById('mem-rows').innerHTML = rows.length ? rows.join('') : '<tr><td colspan="7" style="color:#888;">Память пуста — всё ещё впереди.</td></tr>';
    }, 'json');
}

function editMemoryRow(id) {
    const row = document.querySelector('tr[data-mem-id="' + id + '"] .mem-text');
    if (!row) return;
    const current = row.innerText;
    const next = prompt('Текст записи №' + id + ' (правка переводит запись в manual):', current);
    if (next === null || next.trim() === '' || next === current) return;
    $.post('/api.php', { action: 'save_memory', id: id, text: next, csrf_token: window.csrfToken }, function (res) {
        if (res.success) loadMemory();
        else if (window.showFlashMessage) window.showFlashMessage(res.message, 'error');
    }, 'json');
}

function deleteMemoryRow(id) {
    if (!confirm('Забыть запись №' + id + '? Действие попадёт в журнал модерации.')) return;
    $.post('/api.php', { action: 'delete_memory', id: id, csrf_token: window.csrfToken }, function (res) {
        if (res.success) loadMemory();
        else if (window.showFlashMessage) window.showFlashMessage(res.message, 'error');
    }, 'json');
}

// script.js подключается в head (Application::addJs) — элементы вкладки ещё не в DOM.
document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('fb-rows')) loadFeedback();
    if (document.getElementById('mem-rows')) loadMemory();
    if (document.getElementById('lyra-metrics')) loadLyraMetrics();
});
