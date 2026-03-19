let currentDate = getMSKTime();
let selectedDate = getMSKTime();
let allEvents = [];
let currentPlaylist = [];
let timerInterval = null;
let currentEventData = null; // Store for ICS generation

document.addEventListener('DOMContentLoaded', () => {
    fetchEvents();
});

function fetchEvents() {
    const formData = new FormData();
    formData.append('action', 'get_public_events');
    
    fetch('/api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => {
        // Handle empty or error response gracefully
        if (!res.ok) throw new Error('Network response was not ok');
        const contentType = res.headers.get("content-type");
        if (contentType && contentType.indexOf("application/json") !== -1) {
            return res.json();
        } else {
            return res.text().then(text => {
                console.error('Non-JSON response from API:', text);
                throw new Error('Non-JSON response');
            });
        }
    })
    .then(data => {
        if (data && data.success) {
            allEvents = data.data.events;
            currentPlaylist = data.data.playlist || [];
            
            // Expand recurring events for the next 12 months
            allEvents = expandRecurringEvents(allEvents);
            
            // Use MSK time for initial render
            const mskNow = getMSKTime();
            renderCalendar(mskNow);
            startNextEventTimer();
            // Automatically select today by default
            selectDate(mskNow);
        } else if (data) {
            console.error('Failed to load events:', data.message);
        }
    })
    .catch(err => console.error('Fetch events error:', err));
}

function getMSKTime(date = new Date()) {
    // Получаем текущее абсолютное время в миллисекундах и добавляем смещение MSK (UTC+3)
    const utcTime = date.getTime();
    return new Date(utcTime + (3 * 3600000));
}

function expandRecurringEvents(events) {
    let expanded = [];
    const now = getMSKTime();
    const horizon = new Date(now.getFullYear() + 1, now.getMonth(), now.getDate());
    
    events.forEach(evt => {
        // Parse "YYYY-MM-DD HH:mm:ss" UTC string manually to avoid timezone bugs
        const [datePart, timePart] = evt.start_time.split(' ');
        const [y, m, d] = datePart.split('-');
        const [h, min, s] = (timePart || '00:00:00').split(':');
        
        // В БД лежит UTC время. Мы сдвигаем его в MSK для отображения
        // (с точки зрения JS мы просто создаем локальную дату, которая "выглядит" как MSK)
        const utcStart = new Date(Date.UTC(parseInt(y), parseInt(m) - 1, parseInt(d), parseInt(h), parseInt(min), parseInt(s || 0)));
        const mskStart = new Date(utcStart.getTime() + (3 * 3600000));
        
        expanded.push({ ...evt, parsed_start: mskStart });
        
        if (evt.is_recurring == 1) {
            let nextDate = new Date(mskStart.getTime());
            
            while (nextDate < horizon) {
                if (evt.recurrence_rule === 'daily') {
                    nextDate.setDate(nextDate.getDate() + 1);
                } else if (evt.recurrence_rule === 'weekly') {
                    nextDate.setDate(nextDate.getDate() + 7);
                } else {
                    break; // Unknown rule
                }
                
                if (nextDate < horizon) {
                    expanded.push({ 
                        ...evt, 
                        id: evt.id + '_recur_' + nextDate.getTime(), // fake id for rendering
                        parsed_start: new Date(nextDate.getTime()) 
                    });
                }
            }
        }
    });
    
    return expanded.sort((a, b) => a.parsed_start - b.parsed_start);
}

function renderCalendar(date) {
    currentDate = date;
    const year = date.getFullYear();
    const month = date.getMonth();
    
    const monthNames = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    document.getElementById('current-month-label').textContent = `${monthNames[month]} ${year}`;
    
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    
    let startDayOfWeek = firstDay.getDay() - 1;
    if (startDayOfWeek === -1) startDayOfWeek = 6; // Mon=0, Sun=6
    
    const daysGrid = document.getElementById('calendar-days');
    daysGrid.innerHTML = '';
    
    // Previous month days
    const prevMonthLastDay = new Date(year, month, 0).getDate();
    for (let i = startDayOfWeek - 1; i >= 0; i--) {
        const d = prevMonthLastDay - i;
        daysGrid.appendChild(createDayCell(new Date(year, month - 1, d), true));
    }
    
    // Current month days
    for (let i = 1; i <= lastDay.getDate(); i++) {
        daysGrid.appendChild(createDayCell(new Date(year, month, i), false));
    }
    
    // Next month days to complete grid (42 cells max)
    const totalCellsFilled = startDayOfWeek + lastDay.getDate();
    const cellsRemaining = (totalCellsFilled <= 35 ? 35 : 42) - totalCellsFilled;
    for (let i = 1; i <= cellsRemaining; i++) {
        daysGrid.appendChild(createDayCell(new Date(year, month + 1, i), true));
    }
}

function createDayCell(date, isOtherMonth) {
    const cell = document.createElement('div');
    cell.className = 'calendar-day';
    if (isOtherMonth) cell.classList.add('other-month');
    
    const today = new Date();
    if (date.getDate() === today.getDate() && date.getMonth() === today.getMonth() && date.getFullYear() === today.getFullYear()) {
        cell.classList.add('today');
    }
    
    if (date.getDate() === selectedDate.getDate() && date.getMonth() === selectedDate.getMonth() && date.getFullYear() === selectedDate.getFullYear()) {
        cell.classList.add('selected');
    }
    
    cell.onclick = () => selectDate(date);
    
    const numSpan = document.createElement('span');
    numSpan.className = 'day-number';
    numSpan.textContent = date.getDate();
    cell.appendChild(numSpan);
    
    // Find events for this day
    const dayEvents = allEvents.filter(e => {
        const ed = e.parsed_start;
        return ed.getDate() === date.getDate() && ed.getMonth() === date.getMonth() && ed.getFullYear() === date.getFullYear();
    });
    
    dayEvents.forEach(evt => {
        const badge = document.createElement('div');
        badge.className = 'event-badge';
        badge.style.backgroundColor = evt.color || '#6d2f8e';
        const timeStr = evt.parsed_start.getUTCHours().toString().padStart(2, '0') + ':' + evt.parsed_start.getUTCMinutes().toString().padStart(2, '0');
        badge.textContent = `${timeStr} ${evt.title}`;
        badge.onclick = (e) => {
            e.stopPropagation();
            selectDate(date);
            showEventDetails(evt);
        };
        cell.appendChild(badge);
    });
    
    return cell;
}

function selectDate(date) {
    selectedDate = date;
    
    // Re-render to update selected classes
    renderCalendar(currentDate);
    updateSidebar();
}

function updateSidebar() {
    const label = document.getElementById('selected-date-label');
    const container = document.getElementById('selected-date-events');
    if (!label || !container) return;
    
    const today = new Date();
    if (selectedDate.getDate() === today.getDate() && selectedDate.getMonth() === today.getMonth() && selectedDate.getFullYear() === today.getFullYear()) {
        label.textContent = "События на сегодня";
    } else {
        label.textContent = "События " + selectedDate.toLocaleDateString([], {day: 'numeric', month: 'long'});
    }
    
    const dayEvents = allEvents.filter(e => {
        const ed = e.parsed_start;
        return ed.getDate() === selectedDate.getDate() && ed.getMonth() === selectedDate.getMonth() && ed.getFullYear() === selectedDate.getFullYear();
    });
    
    container.innerHTML = '';
    
    if (dayEvents.length === 0) {
        container.innerHTML = '<div style="color: var(--text-muted); text-align: center; padding: 20px 0;">На эту дату ничего не запланировано.</div>';
        return;
    }
    
    dayEvents.forEach(evt => {
        const card = document.createElement('div');
        card.className = 'selected-event-card';
        card.style.borderLeft = `4px solid ${evt.color || '#6d2f8e'}`;
        card.onclick = () => showEventDetails(evt);
        
        const timeStr = evt.parsed_start.getUTCHours().toString().padStart(2, '0') + ':' + evt.parsed_start.getUTCMinutes().toString().padStart(2, '0');
        const descHtml = (evt.description || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
        
        let durationText = '';
        const dHours = Math.floor(evt.duration_minutes / 60);
        const dMins = evt.duration_minutes % 60;
        if (dHours > 0) durationText += `${dHours} ч `;
        if (dMins > 0) durationText += `${dMins} мин`;
        if (durationText === '') durationText = '0 мин';
        
        card.innerHTML = `
            <div class="selected-event-time">🕒 ${timeStr} МСК (⏳ ${durationText.trim()})</div>
            <h4 class="selected-event-title">${evt.title}</h4>
            <div class="selected-event-desc">${descHtml}</div>
            ${evt.use_playlist == 1 ? '<div style="margin-top:10px; font-size: 0.8em; color: var(--accent-color);">📺 Есть плейлист</div>' : ''}
        `;
        
        container.appendChild(card);
    });
}

function changeMonth(delta) {
    const newDate = new Date(currentDate.getFullYear(), currentDate.getMonth() + delta, 1);
    renderCalendar(newDate);
}

function showEventDetails(evt) {
    currentEventData = evt;
    document.getElementById('modal-event-title').textContent = evt.title;
    
    // Меняем цвет карточки в стиле события
    const color = evt.color || '#6d2f8e';
    document.getElementById('modal-content-card').style.borderTopColor = color;
    document.getElementById('modal-event-title').style.color = color;
    
    document.getElementById('modal-event-time').textContent = evt.parsed_start.getUTCHours().toString().padStart(2, '0') + ':' + evt.parsed_start.getUTCMinutes().toString().padStart(2, '0');
    
    let durationText = '';
    const dHours = Math.floor(evt.duration_minutes / 60);
    const dMins = evt.duration_minutes % 60;
    if (dHours > 0) durationText += `${dHours} ч `;
    if (dMins > 0) durationText += `${dMins} мин`;
    if (durationText === '') durationText = '0 мин';
    
    document.getElementById('modal-event-duration').textContent = durationText.trim();
    
    const descEl = document.getElementById('modal-event-desc');
    // Simple HTML escape and newlines for description (Markdown could be parsed here if library included, for now just text)
    descEl.innerHTML = (evt.description || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
    
    const plContainer = document.getElementById('modal-playlist-container');
    const plContent = document.getElementById('modal-playlist-content');
    
    if (evt.use_playlist == 1 && currentPlaylist) {
        // currentPlaylist may be an object containing stories (due to _meta key)
        const stories = Object.values(currentPlaylist).filter(item => item && Array.isArray(item.titles));
        
        if (stories.length > 0) {
            plContainer.style.display = 'block';
            let html = '<ul style="margin:0; padding-left:20px;">';
            stories.forEach(story => {
                story.titles.forEach(title => {
                    html += `<li><b>${title}</b></li>`;
                });
            });
            html += '</ul>';
            plContent.innerHTML = html;
        } else {
            plContainer.style.display = 'none';
            plContent.innerHTML = '';
        }
    } else {
        plContainer.style.display = 'none';
        plContent.innerHTML = '';
    }
    
    document.getElementById('public-event-modal').style.display = 'flex';
}

function closePublicEventModal() {
    document.getElementById('public-event-modal').style.display = 'none';
    currentEventData = null;
}

function startNextEventTimer() {
    if (timerInterval) clearInterval(timerInterval);
    
    const timerContainer = document.getElementById('next-event-timer');
    const timerSpan = document.getElementById('timer-countdown');
    
    timerInterval = setInterval(() => {
        // Мы перестаем зависеть от now.getTime() (локального времени компа, которое может быть сбито).
        // Так как у нас нет возможности запросить текущее серверное время без AJAX каждую секунду,
        // мы используем Date.now(), но эта проблема с VPN - это то, что VPN сместил _часовой пояс_ локального времени,
        // а не само абсолютное время. Date.now() всегда возвращает абсолютное время UTC.
        // wait, let's fix how we use MSK!
        
        const nowMs = Date.now(); // Это всегда абсолютные миллисекунды от 1970 UTC! Неважно какой пояс.
        
        // В allEvents.forEach мы сохранили mskStart: 
        // const utcStart = new Date(Date.UTC(...)); // абсолютное время в миллисекундах
        // const mskStart = new Date(utcStart.getTime() + (3 * 3600000));
        // Но для таймера нам нужны АБСОЛЮТНЫЕ значения.
        // mskStart.getTime() - это utcStart + 3 часа.
        // Если мы сравниваем его с Date.now(), то мы сравниваем сдвинутое время с несдвинутым! Вот откуда смещение в таймере!
        
        // Значит, нам нужно перевести Date.now() в "псевдо-MSK", добавив те же 3 часа:
        const nowMSK = nowMs + (3 * 3600000);
        
        const futureEvents = allEvents.filter(e => e.parsed_start.getTime() > nowMSK);
        
        if (futureEvents.length > 0) {
            timerContainer.style.display = 'block';
            const nextEvent = futureEvents[0];
            
            const diff = nextEvent.parsed_start.getTime() - nowMSK;
            
            if (diff > 0) {
                const hours = Math.floor(diff / (1000 * 60 * 60));
                const mins = Math.floor((diff / (1000 * 60)) % 60);
                const secs = Math.floor((diff / 1000) % 60);
                
                // If hours > 99, we don't pad to just 2, but padStart won't truncate it. 
                // Let's just calculate days and hours if it's very far.
                const days = Math.floor(hours / 24);
                const displayHours = hours % 24;
                
                if (days > 0) {
                    timerSpan.textContent = `${days}д ${displayHours.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
                } else {
                    timerSpan.textContent = `${hours.toString().padStart(2, '0')}:${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
                }
            } else {
                timerSpan.textContent = "00:00:00";
            }
        } else {
            timerContainer.style.display = 'none';
        }
    }, 1000);
}

function generateICS() {
    if (!currentEventData) return;
    const evt = currentEventData;
    
    const start = evt.parsed_start;
    const end = new Date(start.getTime() + evt.duration_minutes * 60000);
    
    const formatICSDate = (date) => {
        return date.toISOString().replace(/[-:]/g, '').slice(0, 15) + 'Z';
    };
    
    let icsContent = "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//MLP Evening//Calendar//RU\n";
    icsContent += "BEGIN:VEVENT\n";
    icsContent += `UID:${evt.id}-${start.getTime()}@mlpevening\n`;
    icsContent += `DTSTAMP:${formatICSDate(new Date())}\n`;
    icsContent += `DTSTART:${formatICSDate(start)}\n`;
    icsContent += `DTEND:${formatICSDate(end)}\n`;
    icsContent += `SUMMARY:${evt.title}\n`;
    icsContent += `DESCRIPTION:${(evt.description || '').replace(/\n/g, '\\n')}\n`;
    icsContent += "END:VEVENT\nEND:VCALENDAR";
    
    const blob = new Blob([icsContent], { type: 'text/calendar;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `event_${evt.id}.ics`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
