<?php
/**
 * @var array $arResult
 */
?>
<div style="display: flex; gap: 20px; align-items: flex-start;">
    
    <!-- Левая колонка: Управление Паками -->
    <div style="flex: 1; max-width: 300px;">
        <div class="card">
            <h3 class="dashboard-title">📦 Паки</h3>
            <form id="create-pack-form" action="/api.php" method="post" enctype="multipart/form-data" style="margin-bottom: 15px;">
                <input type="hidden" name="action" value="create_pack">
                <input type="text" name="code" placeholder="Код (mane6)" class="form-input" style="margin-bottom: 5px; width: 100%;" required>
                <input type="text" name="name" placeholder="Название (Mane 6)" class="form-input" style="margin-bottom: 5px; width: 100%;" required>
                <label for="create-pack-author" class="form-label">Автор</label>
                <input type="text" name="author_name" id="create-pack-author" maxlength="120" class="form-input" placeholder="Необязательно" style="margin-bottom:5px; width:100%;">
                <label for="create-pack-author-url" class="form-label">Ссылка на автора</label>
                <input type="url" name="author_url" id="create-pack-author-url" maxlength="2048" class="form-input" placeholder="https://…" style="margin-bottom:5px; width:100%;">
                <div style="display:flex; align-items:center; gap:5px; margin-bottom:5px;">
                    <label style="font-size:0.8em; color:#666;">Иконка:</label>
                    <input type="file" name="icon_file" accept="image/*" class="form-input" style="padding:5px; font-size:0.8em;">
                </div>
                <button type="submit" class="btn-primary" style="width: 100%;">Создать Пак</button>
            </form>
            
            <ul id="packs-list" class="pack-list">
                <li>Загрузка...</li>
            </ul>
        </div>

        <!-- ZIP Upload (Global context or per pack) -->
        <div class="card" id="zip-upload-card" style="display: none;">
            <h4 style="margin-top: 0;">📥 Импорт ZIP</h4>
            <p style="font-size: 0.8em; color: #666;">В выбранный пак: <strong id="zip-target-pack-name">...</strong></p>
            <form id="zip-import-form" action="/api.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_zip_stickers">
                <input type="hidden" name="pack_id" id="zip-pack-id">
                <input type="file" name="zip_file" class="form-input" accept=".zip" required>
                <button type="submit" class="btn-warning" style="width: 100%; margin-top: 10px;">Загрузить ZIP</button>
            </form>
        </div>
    </div>

    <!-- Правая колонка: Стикеры -->
    <div style="flex: 3;">
        <div class="card">
            <h3 class="dashboard-title">✨ Стикеры <span id="current-pack-label" style="font-size: 0.7em; color: #aaa;">(Все)</span></h3>
            
            <form id="add-sticker-form" action="/api.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add_sticker">
                
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                    <div style="flex: 1; min-width: 120px;">
                        <label class="form-label">Пак</label>
                        <select name="pack_id" id="sticker-pack-select" class="form-input" required>
                            <option value="">Загрузка...</option>
                        </select>
                    </div>

                    <div style="flex: 1; min-width: 120px;">
                        <label class="form-label">Код</label>
                        <input type="text" name="code" class="form-input" placeholder="happy" required>
                    </div>
                    
                    <div style="flex: 2; min-width: 200px;">
                        <label class="form-label">Файл / Ссылка</label>
                        <input type="file" name="image_file" class="form-input" accept="image/*">
                    </div>

                    <button type="submit" class="btn-primary" style="height: 40px;">➕</button>
                </div>
            </form>

            <div class="search-bar">
                <input type='text' id='stickerSearchInput' placeholder='🔍 Поиск стикеров...' class="search-input">
            </div>

            <table class="dashboard-table" id="stickers-table">
                <thead>
                    <tr>
                        <th width="60">Превью</th>
                        <th>Код</th>
                        <th>Пак</th>
                        <th style="text-align: right;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="4" style="text-align:center;">Выберите пак слева или ждите загрузки...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pack Edit Modal -->
<div id="pack-modal" class="modal-overlay">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('#pack-modal')">&times;</span>
        <h3>📦 Редактировать Пак</h3>
        <form id="edit-pack-form" action="/api.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_pack">
            <input type="hidden" name="id" id="edit_pack_id">
            
            <div class="form-group">
                <label for="edit_pack_author_name" class="form-label">Автор</label>
                <input type="text" name="author_name" id="edit_pack_author_name" maxlength="120" class="form-input" placeholder="Необязательно">
            </div>
            <div class="form-group">
                <label for="edit_pack_author_url" class="form-label">Ссылка на автора</label>
                <input type="url" name="author_url" id="edit_pack_author_url" maxlength="2048" class="form-input" placeholder="https://…">
            </div>
            <div class="form-group">
                <label class="form-label">Название</label>
                <input type="text" name="name" id="edit_pack_name" class="form-input" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Код (системный)</label>
                <input type="text" name="code" id="edit_pack_code" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Иконка (оставьте пустым, если не меняете)</label>
                <input type="file" name="icon_file" class="form-input" accept="image/*">
            </div>
            
            <button type="submit" class="btn-primary" style="width:100%">Сохранить</button>
        </form>
    </div>
</div>
