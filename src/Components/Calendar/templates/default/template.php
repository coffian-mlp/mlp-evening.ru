<section class="calendar-wrapper" aria-labelledby="schedule-title">
    <header class="schedule-heading">
        <div><p class="schedule-eyebrow">MLP Evening</p><h1 id="schedule-title">Расписание</h1><p class="schedule-intro">Встречаемся, смотрим, обсуждаем. Все события — по московскому времени.</p></div>
        <span class="schedule-timezone">МСК · UTC+3</span>
    </header>
    <div id="next-event" class="schedule-next" hidden></div>
    <section class="schedule-month" aria-labelledby="current-month-label">
        <div class="calendar-controls">
            <div class="schedule-month-nav">
                <button type="button" class="schedule-icon-button" data-month-step="-1" aria-label="Предыдущий месяц">‹</button>
                <h2 id="current-month-label"></h2>
                <button type="button" class="schedule-icon-button" data-month-step="1" aria-label="Следующий месяц">›</button>
            </div>
            <button type="button" class="schedule-today" id="schedule-today">Текущий месяц</button>
        </div>
        <p id="schedule-status" class="schedule-status" role="status">Загружаем расписание…</p>
        <div id="schedule-events" class="schedule-events" aria-busy="true"></div>
    </section>
</section>
<div id="public-event-modal" class="schedule-modal" hidden>
    <section class="schedule-dialog" id="modal-content-card" role="dialog" aria-modal="true" aria-labelledby="modal-event-title" tabindex="-1">
        <button type="button" class="schedule-modal-close" aria-label="Закрыть подробности">×</button>
        <p id="modal-event-date" class="schedule-eyebrow"></p>
        <h2 id="modal-event-title"></h2>
        <div class="schedule-modal-meta"><span>Начало: <strong id="modal-event-time"></strong> МСК</span><span>Длительность: <strong id="modal-event-duration"></strong></span></div>
        <p id="modal-event-desc" class="schedule-description"></p>
        <section id="modal-playlist-container" hidden><h3>Текущий плейлист</h3><p class="schedule-playlist-note">К началу события подборка может измениться.</p><ul id="modal-playlist-content"></ul></section>
        <button type="button" class="schedule-ics" id="schedule-export">Добавить в календарь (.ics)</button>
    </section>
</div>
