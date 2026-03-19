<?php
/** 
 * @var array $arResult 
 */
?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 class="dashboard-title">📅 Расписание Событий</h3>
        <button class="btn-primary" onclick="openEventModal()">➕ Добавить событие</button>
    </div>
    
    <div class="table-responsive">
        <table class="dashboard-table admin-table" id="events-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th style="text-align: center;">Цвет</th>
                    <th>Название</th>
                    <th>Время начала</th>
                    <th>Длительность</th>
                    <th>Повторение</th>
                    <th>Плейлист</th>
                    <th style="text-align: right;">Действия</th>
                </tr>
            </thead>
            <tbody id="events-table-body">
                <?php foreach ($arResult['events'] as $event): ?>
                    <tr data-id="<?= $event['id'] ?>">
                        <td><?= $event['id'] ?></td>
                        <td style="text-align: center;">
                            <div class="color-swatch" style="background-color: <?= htmlspecialchars($event['color']) ?>" title="<?= htmlspecialchars($event['color']) ?>"></div>
                        </td>
                        <td><?= htmlspecialchars($event['title']) ?></td>
                        <td><?= htmlspecialchars($event['start_time_msk']) ?> (МСК)</td>
                        <td><?= (int)$event['duration_minutes'] ?> мин</td>
                        <td>
                            <?= $event['is_recurring'] ? 'Да (' . htmlspecialchars($event['recurrence_rule']) . ')' : 'Нет' ?>
                        </td>
                        <td>
                            <?php if ($event['use_playlist']): ?>
                                <span class="badge badge-primary">Использует</span>
                            <?php endif; ?>
                            <?php if ($event['generate_new_playlist']): ?>
                                <span class="badge badge-warning">Генерирует</span>
                            <?php endif; ?>
                            <?php if (!$event['use_playlist'] && !$event['generate_new_playlist']): ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <button class="btn-primary" style="padding: 5px 10px; font-size: 0.9em;" onclick='editEvent(<?= json_encode($event, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✎</button>
                            <button class="btn-danger" style="padding: 5px 10px; font-size: 0.9em;" onclick="deleteEvent(<?= $event['id'] ?>)">🗑</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($arResult['events'])): ?>
                    <tr>
                        <td colspan="8" style="text-align: center;">Событий пока нет. Создайте первое!</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal for Add/Edit Event -->
<div id="event-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 600px; text-align: left;">
        <span class="close-modal" onclick="closeEventModal()">&times;</span>
        <h3 id="event-modal-title">Добавить событие</h3>
        
        <form id="event-form" onsubmit="saveEvent(event)">
            <input type="hidden" id="event_id" name="id" value="">
            
            <div class="form-group mb-2">
                <label>Название события:</label>
                <input type="text" id="event_title" name="title" class="form-input" required>
            </div>
            
            <div class="form-group mb-2">
                <label>Описание (можно с Markdown):</label>
                <textarea id="event_description" name="description" class="form-input" rows="4"></textarea>
            </div>
            
            <div class="grid-2col mb-2" style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>Начало (Время МСК, UTC+3):</label>
                    <input type="datetime-local" id="event_start_time" class="form-input" required>
                </div>
                <div class="form-group">
                    <label>Длительность (мин):</label>
                    <input type="number" id="event_duration_minutes" name="duration_minutes" class="form-input" value="60" min="1" required>
                </div>
            </div>
            
            <div class="grid-2col mb-2" style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group" style="display:flex; flex-direction:column; justify-content:center;">
                    <label class="checkbox-label" style="display:flex; align-items:center; gap:5px;">
                        <input type="checkbox" id="event_is_recurring" name="is_recurring" onchange="toggleRecurrence()">
                        Повторяющееся
                    </label>
                </div>
                <div class="form-group">
                    <label>Цвет в календаре:</label>
                    <input type="color" id="event_color" name="color" value="#6d2f8e" style="height:35px; width:100%;">
                </div>
            </div>
            
            <div id="recurrence_options" class="form-group mb-3" style="display:none; background:rgba(0,0,0,0.2); padding:10px; border-radius:5px; border:1px solid rgba(255,255,255,0.1);">
                <label>Правило повторения:</label>
                <select id="event_recurrence_rule" name="recurrence_rule" class="form-input">
                    <option value="weekly">Еженедельно</option>
                    <option value="daily">Ежедневно (Марафон)</option>
                </select>
            </div>
            
            <div class="form-group mb-3 p-2" style="border: 1px dashed rgba(206,147,216,0.5); border-radius: 5px; background: rgba(0,0,0,0.2);">
                <label style="font-weight:bold; color:#6d2f8e; margin-bottom:5px; display:block;">Настройки плейлиста:</label>
                <label class="checkbox-label mb-1" style="display:flex; align-items:center; gap:5px;">
                    <input type="checkbox" id="event_use_playlist" name="use_playlist">
                    Привязать текущий плейлист к событию
                </label>
                <label class="checkbox-label" style="display:flex; align-items:center; gap:5px;">
                    <input type="checkbox" id="event_generate_new_playlist" name="generate_new_playlist">
                    Генерировать новый плейлист после окончания
                </label>
                <small style="color:#666; display:block; margin-top:5px;">
                    Внимание: Можно иметь только <b>одно</b> регулярное событие с этими флагами!
                </small>
            </div>
            
            <div class="form-actions mt-3">
                <button type="submit" class="btn-primary">💾 Сохранить</button>
                <button type="button" class="btn-danger" onclick="closeEventModal()">Отмена</button>
            </div>
        </form>
    </div>
</div>
