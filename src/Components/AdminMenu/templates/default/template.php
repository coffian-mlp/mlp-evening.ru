<?php
/**
 * @var array $arResult
 * Редактор меню сайта (MLP-259): таблица дерева + модалка CRUD.
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 class="dashboard-title">🧭 Меню сайта</h3>
        <button class="btn-primary" onclick="openMenuItemModal()">➕ Добавить пункт</button>
    </div>
    <p style="color:#999; font-size:0.9em; margin-top:0;">
        Одно меню — две подачи: шапка страниц и сенобургер на главной. Пункт без адреса — некликабельная раскрывашка для вложенных.
    </p>

    <table class="dashboard-table" id="menu-items-table">
        <thead>
            <tr>
                <th style="width:90px;">Порядок</th>
                <th>Заголовок</th>
                <th>Адрес</th>
                <th>Видимость</th>
                <th>Подача</th>
                <th>Статус</th>
                <th style="text-align: right;">Действия</th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="7" style="text-align:center;">Загрузка...</td></tr>
        </tbody>
    </table>
</div>

<!-- Modal: пункт меню -->
<div id="menu-item-modal" class="modal-overlay">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('#menu-item-modal')">&times;</span>
        <h3 id="menu-item-modal-title">Пункт меню</h3>
        <form id="menu-item-form" action="/api.php" method="post">
            <input type="hidden" name="action" value="save_menu_item">
            <input type="hidden" name="id" id="menu_item_id">

            <div class="form-group">
                <label class="form-label">Заголовок (эмодзи приветствуются)</label>
                <input type="text" name="title" id="menu_item_title" class="form-input" required maxlength="100">
            </div>

            <div class="form-group">
                <label class="form-label">Адрес</label>
                <input type="text" name="url" id="menu_item_url" class="form-input" placeholder="/schedule.php или https://… (пусто = раскрывашка)">
            </div>

            <div class="form-group">
                <label class="form-label">Родитель</label>
                <select name="parent_id" id="menu_item_parent" class="form-input">
                    <option value="0">— корень меню —</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Видимость</label>
                <select name="visibility" id="menu_item_visibility" class="form-input">
                    <option value="all">Всем</option>
                    <option value="users">Залогиненным</option>
                    <option value="admins">Только админам</option>
                </select>
            </div>

            <div class="form-group" style="display:flex; flex-direction:column; gap:6px;">
                <label style="cursor:pointer;"><input type="checkbox" name="is_external" id="menu_item_external" value="1"> Внешняя ссылка (новая вкладка + ↗)</label>
                <label style="cursor:pointer;"><input type="checkbox" name="show_in_header" id="menu_item_in_header" value="1" checked> Показывать в шапке</label>
                <label style="cursor:pointer;"><input type="checkbox" name="show_in_burger" id="menu_item_in_burger" value="1" checked> Показывать в сенобургере</label>
                <label style="cursor:pointer;"><input type="checkbox" name="is_active" id="menu_item_active" value="1" checked> Включён</label>
                <small style="color:#999;">Шапка есть на /schedule, /login и в дашборде. На <b>главной</b> шапки нет —
                там только сенобургер: пункт «только шапка» на главной не виден нигде.</small>
            </div>

            <button type="submit" class="btn-primary" style="width:100%">💾 Сохранить</button>
        </form>
    </div>
</div>
