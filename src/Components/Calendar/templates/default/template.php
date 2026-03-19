<?php
/** 
 * @var array $arResult 
 */
?>
<div class="calendar-wrapper">
    <div class="calendar-header">
        <h2 style="color: var(--accent-color); margin-top: 0;">📅 Календарь Эквестрии</h2>
        
        <div id="next-event-timer" class="next-event-timer" style="display:none;">
            Следующее событие через: <span id="timer-countdown">--:--:--</span>
        </div>
        
        <div class="calendar-controls">
            <button class="btn-primary btn-sm" onclick="changeMonth(-1)">&#9664;</button>
            <h3 id="current-month-label" style="margin: 0 15px; min-width: 150px; text-align: center;"></h3>
            <button class="btn-primary btn-sm" onclick="changeMonth(1)">&#9654;</button>
            <button class="btn-primary btn-sm" style="margin-left: 15px;" onclick="renderCalendar(getMSKTime()); selectDate(getMSKTime());">Сегодня</button>
        </div>
    </div>
    
    <div class="calendar-layout-split">
        <div class="calendar-main">
            <div class="calendar-grid">
                <div class="calendar-days-header">
                    <div>Пн</div><div>Вт</div><div>Ср</div><div>Чт</div><div>Пт</div><div class="weekend">Сб</div><div class="weekend">Вс</div>
                </div>
                <div id="calendar-days" class="calendar-days">
                    <!-- Days will be rendered here via JS -->
                </div>
            </div>
        </div>
        
        <div class="calendar-sidebar">
            <h3 id="selected-date-label" style="color: var(--accent-color); margin-top: 0; margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px;">События на сегодня</h3>
            <div id="selected-date-events" class="selected-events-list">
                <!-- Events will be rendered here -->
            </div>
        </div>
    </div>
</div>

<!-- Modal for Public Event Details -->
<div id="public-event-modal" class="modal-overlay" style="display: none;">
    <div class="modal-content" id="modal-content-card" style="max-width: 600px; text-align: left; border-top: 4px solid var(--accent-color);">
        <span class="close-modal" onclick="closePublicEventModal()">&times;</span>
        <h2 id="modal-event-title" class="modal-title" style="margin-top:0; text-align: center; font-size: 1.8em;"></h2>
        
        <div class="event-meta mb-3" style="color: var(--text-muted); font-size: 0.95em; display: flex; justify-content: center; gap: 20px; background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; margin-bottom: 20px;">
            <div>🕒 <strong id="modal-event-time" style="color: #fff;"></strong> (МСК)</div>
            <div>⏳ <strong id="modal-event-duration" style="color: #fff;"></strong></div>
        </div>
        
        <div id="modal-event-desc" class="event-desc mb-3" style="line-height: 1.6; font-size: 1.05em; margin-bottom: 20px;"></div>
        
        <div id="modal-playlist-container" style="display:none; margin-bottom: 20px;">
            <h4 class="modal-subtitle" style="color:var(--accent-color); margin-bottom:10px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px;">📺 Плейлист на вечер</h4>
            <div id="modal-playlist-content" style="background:rgba(0,0,0,0.3); padding:15px; border-radius:8px; border:1px solid rgba(255,255,255,0.1); max-height:200px; overflow-y:auto; font-size: 0.95em;">
            </div>
        </div>
        
        <div class="form-actions mt-3" style="text-align: center;">
            <button class="btn-primary" onclick="generateICS()" style="width: 100%; padding: 12px; font-size: 1.1em; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.3);">🗓️ Добавить в календарь (.ics)</button>
        </div>
    </div>
</div>
