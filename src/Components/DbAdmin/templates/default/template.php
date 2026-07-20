<?php
use Domain\Auth;
/**
 * @var array $arResult
 */

// MLP-257: текущее состояние просмотра (фильтры + сортировка) для ссылок
// пагинации/сортировки и hidden-полей экспорта — одна сборка, ноль рассинхрона.
$dbViewParams = ['db_action' => 'view', 'table' => $arResult['current_table']];
foreach (($arResult['filters'] ?? []) as $f) {
    $dbViewParams['filter_col'][] = $f['col'];
    $dbViewParams['filter_op'][]  = $f['op'];
    $dbViewParams['filter_val'][] = $f['val'];
}
$dbSort = $arResult['sort'] ?? ['col' => '', 'dir' => 'asc'];
if ($dbSort['col'] !== '') {
    $dbViewParams['sort'] = $dbSort['col'];
    $dbViewParams['dir']  = $dbSort['dir'];
}

/** Ссылка просмотра с переопределением параметров (page/sort и т.п.). */
$dbViewUrl = function (array $override = []) use ($dbViewParams): string {
    return '?' . http_build_query(array_merge($dbViewParams, $override)) . '#tab-database';
};
?>
<div class="db-admin-container">

    <!-- Sidebar: Table List -->
    <div class="card" style="width: 250px; flex-shrink: 0; padding: 15px;">
        <h4 style="margin-top: 0; margin-bottom: 15px;">📂 Таблицы</h4>
        <div class="table-list" style="max-height: 600px; overflow-y: auto;">
            <?php foreach ($arResult['tables'] as $tbl): ?>
                <a href="?db_action=view&table=<?= $tbl ?>#tab-database" 
                   class="table-link <?= ($arResult['current_table'] === $tbl) ? 'active' : '' ?>">
                   📄 <?= $tbl ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Main Content: Data View -->
    <div class="card" style="flex-grow: 1; overflow: hidden; display: flex; flex-direction: column;">
        
        <?php if ($arResult['error']): ?>
            <div class="alert alert-danger" style="color: red; padding: 10px; background: #ffebee; border-radius: 4px;">
                <?= htmlspecialchars($arResult['error']) ?>
            </div>
        <?php endif; ?>

        <?php if ($arResult['current_table']): ?>
            <div class="table-header" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0;">
                        Таблица: <span style="color: #6d2f8e;"><?= htmlspecialchars($arResult['current_table']) ?></span>
                        <small style="color: #888; font-size: 0.6em;">(<?= $arResult['pagination']['total_rows'] ?? 0 ?> строк)</small>
                    </h3>
                    
                    <div class="actions">
                        <!-- MLP-255: экспорт — POST на api.php; MLP-257: переносит ВСЕ фильтры и сортировку -->
                        <form id="db-export-form" method="post" action="/api.php" target="_blank" style="display: inline-block;">
                            <input type="hidden" name="action" value="db_export">
                            <input type="hidden" name="csrf_token" value="<?= Auth::generateCsrfToken() ?>">
                            <input type="hidden" name="table" value="<?= htmlspecialchars($arResult['current_table']) ?>">
                            <?php foreach (($arResult['filters'] ?? []) as $f): ?>
                                <input type="hidden" name="filter_col[]" value="<?= htmlspecialchars($f['col']) ?>">
                                <input type="hidden" name="filter_op[]" value="<?= htmlspecialchars($f['op']) ?>">
                                <input type="hidden" name="filter_val[]" value="<?= htmlspecialchars($f['val']) ?>">
                            <?php endforeach; ?>
                            <?php if ($dbSort['col'] !== ''): ?>
                                <input type="hidden" name="sort" value="<?= htmlspecialchars($dbSort['col']) ?>">
                                <input type="hidden" name="dir" value="<?= htmlspecialchars($dbSort['dir']) ?>">
                            <?php endif; ?>
                            <button type="submit" class="btn-primary">⬇️ Экспорт CSV</button>
                        </form>
                    </div>
                </div>

                <!-- Filter Form (MLP-257: несколько условий, AND) -->
                <?php
                    // Строки формы: активные условия + одна пустая для ввода нового
                    $filterRows = $arResult['filters'] ?? [];
                    $filterRows[] = ['col' => '', 'op' => '=', 'val' => ''];
                    $dbOperators = ['=' => '=', 'LIKE' => 'LIKE %...%', '!=' => '!=', '>' => '>', '<' => '<', '>=' => '>=', '<=' => '<=', 'BETWEEN' => 'BETWEEN (min,max)'];
                ?>
                <form method="get" action="" class="db-filter-form">
                    <input type="hidden" name="db_action" value="view">
                    <input type="hidden" name="table" value="<?= htmlspecialchars($arResult['current_table']) ?>">
                    <?php if ($dbSort['col'] !== ''): ?>
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($dbSort['col']) ?>">
                        <input type="hidden" name="dir" value="<?= htmlspecialchars($dbSort['dir']) ?>">
                    <?php endif; ?>

                    <span class="db-filter-label">Фильтры (И):</span>

                    <div id="db-filter-rows" style="display: flex; flex-direction: column; gap: 6px; flex: 1;">
                        <?php foreach ($filterRows as $i => $row): ?>
                            <div class="db-filter-row" style="display: flex; gap: 8px; align-items: center;">
                                <select name="filter_col[]" class="form-input db-filter-select">
                                    <option value="">-- Колонка --</option>
                                    <?php foreach ($arResult['columns'] as $col): ?>
                                        <option value="<?= htmlspecialchars($col) ?>" <?= ($row['col'] === $col) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($col) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <select name="filter_op[]" class="form-input db-filter-select" style="min-width: 80px;">
                                    <?php foreach ($dbOperators as $opVal => $opLabel): ?>
                                        <option value="<?= htmlspecialchars($opVal) ?>" <?= ($row['op'] === $opVal) ? 'selected' : '' ?>><?= htmlspecialchars($opLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <input type="text" name="filter_val[]" value="<?= htmlspecialchars($row['val']) ?>" class="form-input db-filter-input" placeholder="Значение...">

                                <button type="button" class="btn-xs btn-danger db-filter-remove" title="Убрать условие" onclick="dbRemoveFilterRow(this)">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display: flex; gap: 8px; align-items: center;">
                        <button type="button" class="btn-xs" onclick="dbAddFilterRow()" title="Ещё условие">➕</button>
                        <button type="submit" class="btn-primary db-filter-btn">🔍 Найти</button>
                        <?php if (!empty($arResult['filters'])): ?>
                            <?php
                                // Сброс убирает фильтры, но сохраняет сортировку (ревью MLP-257)
                                $resetParams = ['db_action' => 'view', 'table' => $arResult['current_table']];
                                if ($dbSort['col'] !== '') { $resetParams['sort'] = $dbSort['col']; $resetParams['dir'] = $dbSort['dir']; }
                            ?>
                            <a href="?<?= htmlspecialchars(http_build_query($resetParams)) ?>#tab-database" class="db-filter-reset" title="Сбросить фильтры">❌</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div style="overflow-x: auto; max-height: 70vh;">
                <table class="dashboard-table" style="font-size: 0.85em;">
                    <thead>
                        <tr>
                            <th style="width: 50px;">🔧</th>
                            <?php foreach ($arResult['columns'] as $col): ?>
                                <?php
                                    // MLP-257: серверная сортировка — повторный клик переключает направление
                                    $isSorted = ($dbSort['col'] === $col);
                                    $nextDir = ($isSorted && $dbSort['dir'] === 'asc') ? 'desc' : 'asc';
                                    $indicator = $isSorted ? ($dbSort['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
                                ?>
                                <th class="db-sortable">
                                    <a href="<?= htmlspecialchars($dbViewUrl(['sort' => $col, 'dir' => $nextDir, 'page' => 1])) ?>"
                                       style="color: inherit; text-decoration: none;">
                                        <?= htmlspecialchars($col) ?><?= $indicator ?>
                                    </a>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($arResult['rows'])): ?>
                            <tr><td colspan="<?= count($arResult['columns']) + 1 ?>" style="text-align: center;">Нет данных</td></tr>
                        <?php else: ?>
                            <?php foreach ($arResult['rows'] as $row): ?>
                                <tr>
                                    <td>
                                        <?php if ($arResult['pk']): ?>
                                            <button class="btn-xs btn-warning edit-row-btn" 
                                                    data-id="<?= htmlspecialchars($row[$arResult['pk']]) ?>"
                                                    data-table="<?= htmlspecialchars($arResult['current_table']) ?>">
                                                ✏️
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($row as $val): ?>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($val ?? 'NULL') ?>">
                                            <?php 
                                            if ($val === null) echo '<span style="color: #ccc;">NULL</span>';
                                            elseif (strlen($val) > 100) echo htmlspecialchars(substr($val, 0, 100)) . '...';
                                            else echo htmlspecialchars($val);
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($arResult['pagination']['total_pages'] > 1): ?>
                <div class="pagination" style="margin-top: 15px; display: flex; gap: 5px; justify-content: center;">
                    <?php
                    $cur = $arResult['pagination']['current'];
                    $total = $arResult['pagination']['total_pages'];
                    // MLP-257: ссылки страниц переносят все фильтры и сортировку ($dbViewUrl)
                    if ($cur > 1): ?>
                        <a href="<?= htmlspecialchars($dbViewUrl(['page' => $cur - 1])) ?>" class="btn-xs">←</a>
                    <?php endif; ?>

                    <span style="padding: 5px 10px;">Стр. <?= $cur ?> из <?= $total ?></span>

                    <?php if ($cur < $total): ?>
                        <a href="<?= htmlspecialchars($dbViewUrl(['page' => $cur + 1])) ?>" class="btn-xs">→</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div style="text-align: center; padding: 50px; color: #888;">
                <p>👈 Выберите таблицу из списка слева</p>
            </div>
        <?php endif; ?>
    </div>
</div>

    </div>
</div>

<!-- Edit Row Modal -->
<div id="db-edit-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 600px;">
        <span class="close-modal" onclick="document.getElementById('db-edit-modal').style.display='none'">&times;</span>
        <h3>📝 Редактировать запись</h3>
        <form id="db-edit-form">
            <input type="hidden" name="action" value="db_update_row">
            <input type="hidden" name="table" id="edit_table_name">
            <input type="hidden" name="__pk_value" id="edit_pk_value">
            
            <div id="db-edit-fields" style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
                <!-- Fields injected via JS -->
            </div>
            
            <div style="margin-top: 20px; display: flex; justify-content: flex-end;">
                <button type="submit" class="btn-primary">Сохранить изменения</button>
            </div>
        </form>
    </div>
</div>

<script>
// MLP-257: динамические строки мультифильтра
function dbAddFilterRow() {
    var rows = document.getElementById('db-filter-rows');
    var row = rows.querySelector('.db-filter-row').cloneNode(true);
    row.querySelector('select[name="filter_col[]"]').selectedIndex = 0;
    row.querySelector('select[name="filter_op[]"]').selectedIndex = 0;
    row.querySelector('input[name="filter_val[]"]').value = '';
    rows.appendChild(row);
}
function dbRemoveFilterRow(btn) {
    var rows = document.getElementById('db-filter-rows');
    var row = btn.closest('.db-filter-row');
    if (rows.querySelectorAll('.db-filter-row').length > 1) {
        row.remove();
    } else {
        // последняя строка — просто очищаем
        row.querySelector('select[name="filter_col[]"]').selectedIndex = 0;
        row.querySelector('select[name="filter_op[]"]').selectedIndex = 0;
        row.querySelector('input[name="filter_val[]"]').value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Edit Button Click Handler
    document.querySelectorAll('.edit-row-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const table = this.dataset.table;
            const id = this.dataset.id;
            openEditModal(table, id);
        });
    });

    // Form Submit Handler
    document.getElementById('db-edit-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const form = this;
        const formData = new FormData(form);
        const btn = form.querySelector('button[type="submit"]');
        const originalText = btn.innerText;
        
        btn.disabled = true;
        btn.innerText = 'Сохранение...';

        // MLP-255: транспорт через api.php (action=db_update_row в hidden-поле формы)
        fetch('/api.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': window.csrfToken || '' }, // MLP-243: CSRF на мутацию БД
            body: formData
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                // Use global Flash Message
                if (window.showFlashMessage) {
                    window.showFlashMessage('✅ Запись обновлена!', 'success');
                } else {
                    alert('✅ Запись обновлена!');
                }
                
                document.getElementById('db-edit-modal').style.display = 'none';
                
                // Reload page after short delay to let user see the message
                setTimeout(() => location.reload(), 1000);
            } else {
                if (window.showFlashMessage) {
                    window.showFlashMessage('❌ Ошибка: ' + res.message, 'error');
                } else {
                    alert('❌ Ошибка: ' + res.message);
                }
            }
        })
        .catch(err => {
            if (window.showFlashMessage) {
                window.showFlashMessage('Ошибка сети: ' + err, 'error');
            } else {
                alert('Ошибка сети: ' + err);
            }
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerText = originalText;
        });
    });
});

function openEditModal(table, id) {
    const modal = document.getElementById('db-edit-modal');
    const fieldsContainer = document.getElementById('db-edit-fields');
    fieldsContainer.innerHTML = '<p style="text-align:center;">Загрузка данных...</p>';
    modal.style.display = 'flex';
    
    document.getElementById('edit_table_name').value = table;
    document.getElementById('edit_pk_value').value = id;

    // Fetch Row Data (MLP-255: POST на api.php)
    fetch('/api.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.csrfToken || '' },
        body: new URLSearchParams({ action: 'db_get_row', table: table, id: id })
    })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                fieldsContainer.innerHTML = `<p style="color:red;">Ошибка: ${res.message}</p>`;
                return;
            }
            
            fieldsContainer.innerHTML = ''; // Clear loading
            const data = res.data;
            const types = res.types;
            const pk = res.pk;

            for (const [col, val] of Object.entries(data)) {
                const type = types[col] || 'text';
                const isPk = (col === pk);
                const safeVal = val === null ? '' : val; // Handle nulls as empty string for inputs
                
                let inputHtml = '';
                
                // Determine input type based on SQL type
                if (type.includes('text') || type.includes('varchar') && type.includes('255')) {
                     // Textarea for long text
                     inputHtml = `<textarea name="${col}" class="form-input" rows="3">${escapeHtml(safeVal)}</textarea>`;
                } else if (type.includes('int') || type.includes('float') || type.includes('double')) {
                     inputHtml = `<input type="number" name="${col}" value="${escapeHtml(safeVal)}" class="form-input" ${isPk ? 'readonly style="opacity:0.7;background:#333;"' : ''}>`;
                } else if (type.includes('datetime') || type.includes('timestamp')) {
                     // Convert SQL datetime to input datetime-local format (YYYY-MM-DDTHH:MM) if needed
                     let dateVal = safeVal.replace(' ', 'T'); 
                     inputHtml = `<input type="datetime-local" name="${col}" value="${escapeHtml(dateVal)}" class="form-input">`;
                } else {
                     // Default text
                     inputHtml = `<input type="text" name="${col}" value="${escapeHtml(safeVal)}" class="form-input" ${isPk ? 'readonly style="opacity:0.7;background:#333;"' : ''}>`;
                }

                const fieldHtml = `
                    <div class="form-group">
                        <label class="form-label">${escapeHtml(col)} <small style="color:#666;">(${type})</small></label>
                        ${inputHtml}
                    </div>
                `;
                fieldsContainer.insertAdjacentHTML('beforeend', fieldHtml);
            }
        })
        .catch(err => {
            fieldsContainer.innerHTML = `<p style="color:red;">Ошибка сети: ${err}</p>`;
        });
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>

<style>
/* CSS Moved to style.css */
</style>
