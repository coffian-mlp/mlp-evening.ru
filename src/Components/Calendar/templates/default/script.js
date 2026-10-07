(() => {
    const root = document.querySelector('.calendar-wrapper');
    if (!root) return;
    const modal = document.getElementById('public-event-modal');
    const dialog = document.getElementById('modal-content-card');
    const list = document.getElementById('schedule-events');
    const status = document.getElementById('schedule-status');
    const next = document.getElementById('next-event');
    const initialMono = performance.now();
    const initialTime = Number.isFinite(Number(window.serverTime)) && Number(window.serverTime) > 0 ? Number(window.serverTime) * 1000 : Date.now();
    const now = () => initialTime + performance.now() - initialMono;
    const msk = date => new Date(date.getTime() + 3 * 3600000);
    const dateKey = date => msk(date).toISOString().slice(0, 10);
    const format = (date, options) => new Intl.DateTimeFormat('ru-RU', {timeZone: 'Europe/Moscow', ...options}).format(date);
    const duration = value => {
        const minutes = Math.max(0, Number(value) || 0);
        return [Math.floor(minutes / 60) ? `${Math.floor(minutes / 60)} ч` : '', minutes % 60 ? `${minutes % 60} мин` : ''].filter(Boolean).join(' ') || '0 мин';
    };
    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const currentMonth = () => {const d = msk(new Date(now())); return new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1));};
    let month = currentMonth();
    let events = [];
    let playlist = [];
    let currentEvent = null;
    let opener = null;
    let previousOverflow = '';
    let nextKey = null;
    let timer = null;
    let loaded = false;
    let loading = false;

    function expand(source) {
        const result = [];
        const horizon = new Date(now());
        horizon.setUTCFullYear(horizon.getUTCFullYear() + 1);
        source.forEach(event => {
            const match = String(event.start_time).match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/);
            if (!match) return;
            const start = new Date(Date.UTC(...match.slice(1).map((v, i) => Number(v) - (i === 1 ? 1 : 0))));
            if (!Number.isFinite(start.getTime())) return;
            const step = event.is_recurring == 1 ? ({daily: 1, weekly: 7}[event.recurrence_rule] || 0) : 0;
            let date = start;
            do {
                result.push({...event, start: new Date(date), key: `${event.id}:${date.getTime()}`});
                if (!step) break;
                date = new Date(date.getTime() + step * 86400000);
            } while (date < horizon);
        });
        return result.sort((a, b) => a.start - b.start || String(a.id).localeCompare(String(b.id)));
    }

    async function fetchEvents() {
        if (loading) return;
        loading = true;
        status.textContent = 'Загружаем расписание…';
        list.setAttribute('aria-busy', 'true');
        try {
            const body = new FormData(); body.append('action', 'get_public_events');
            const response = await fetch('/api.php', {method: 'POST', body});
            if (!response.ok) throw new Error('HTTP');
            const data = await response.json();
            if (!data.success || !Array.isArray(data.data?.events)) throw new Error('Payload');
            events = expand(data.data.events);
            playlist = data.data.playlist || [];
            loaded = true;
            renderMonth();
            updateNext();
            if (timer) clearInterval(timer);
            timer = setInterval(updateNext, 1000);
        } catch (error) {
            status.replaceChildren(el('span', '', 'Не удалось загрузить расписание.'));
            const retry = el('button', '', 'Повторить'); retry.type = 'button'; retry.onclick = fetchEvents;
            status.append(retry);
        } finally {
            loading = false;
            list.setAttribute('aria-busy', 'false');
        }
    }

    function eventButton(event) {
        const button = el('button', 'schedule-event-card'); button.type = 'button';
        button.style.setProperty('--event-color', /^#[0-9a-f]{6}$/i.test(event.color || '') ? event.color : '#bd8dd6');
        const time = el('span', 'schedule-event-time', format(event.start, {hour: '2-digit', minute: '2-digit'}));
        time.append(el('span', 'schedule-event-duration', duration(event.duration_minutes)));
        const body = el('span', 'schedule-event-body');
        body.append(el('span', 'schedule-event-title', event.title));
        if (event.description) body.append(el('span', 'schedule-event-desc', event.description));
        const badges = el('span', 'schedule-event-badges');
        if (event.is_recurring == 1) badges.append(el('span', '', event.recurrence_rule === 'daily' ? 'Каждый день' : 'Каждую неделю'));
        if (event.use_playlist == 1) badges.append(el('span', '', 'С плейлистом'));
        if (event.start.getTime() + Number(event.duration_minutes) * 60000 <= now()) badges.append(el('span', '', 'Завершено'));
        if (badges.childNodes.length) body.append(badges);
        const arrow = el('span', 'schedule-event-arrow', '›'); arrow.setAttribute('aria-hidden', 'true');
        button.append(time, body, arrow);
        button.onclick = () => openDetails(event, button);
        return button;
    }

    function renderMonth() {
        const label = new Intl.DateTimeFormat('ru-RU', {month: 'long', year: 'numeric', timeZone: 'UTC'}).format(month);
        document.getElementById('current-month-label').textContent = label.charAt(0).toUpperCase() + label.slice(1);
        if (!loaded) return;
        const key = month.toISOString().slice(0, 7);
        const visible = events.filter(event => dateKey(event.start).startsWith(key));
        status.textContent = visible.length ? `Событий в этом месяце: ${visible.length}. Время указано в МСК.` : 'Время указано в МСК.';
        list.replaceChildren();
        if (!visible.length) {
            list.append(el('p', 'schedule-empty', 'На этот месяц пока ничего не запланировано. Попробуй соседний месяц.'));
            return;
        }
        let groupKey = null;
        let cards;
        visible.forEach(event => {
            if (dateKey(event.start) !== groupKey) {
                groupKey = dateKey(event.start);
                const group = el('section', 'schedule-day-group');
                const date = el('h3', 'schedule-date');
                date.append(el('strong', '', format(event.start, {day: 'numeric'})), el('span', '', format(event.start, {month: 'long'})), el('span', '', format(event.start, {weekday: 'long'})));
                cards = el('div', 'schedule-day-cards'); group.append(date, cards); list.append(group);
            }
            cards.append(eventButton(event));
        });
    }

    function updateNext() {
        const event = events.find(e => e.start.getTime() + Number(e.duration_minutes) * 60000 > now());
        if (!event) {next.hidden = true; nextKey = null; return;}
        const live = event.start.getTime() <= now();
        const key = `${event.key}:${live}`;
        if (key !== nextKey) {
            nextKey = key;
            const meta = el('div', 'schedule-next-meta');
            meta.append(el('span', '', format(event.start, {weekday: 'long', day: 'numeric', month: 'long'})), el('span', '', `${format(event.start, {hour: '2-digit', minute: '2-digit'})} МСК`), el('span', '', duration(event.duration_minutes)));
            const bottom = el('div', 'schedule-next-bottom');
            const countdown = el('span', 'schedule-countdown'); countdown.id = 'timer-countdown';
            const button = el('button', '', 'Подробнее о событии'); button.type = 'button'; button.onclick = () => openDetails(event, button);
            bottom.append(countdown, button);
            next.replaceChildren(el('p', 'schedule-eyebrow', live ? 'Идёт сейчас' : 'Ближайшая встреча'), el('h2', '', event.title), meta, bottom);
        }
        next.hidden = false;
        const seconds = Math.max(0, Math.floor((event.start - now()) / 1000));
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor(seconds % 86400 / 3600);
        const mins = Math.floor(seconds % 3600 / 60);
        document.getElementById('timer-countdown').textContent = live ? 'Событие уже началось' : `До начала: ${days ? `${days} д ` : ''}${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
    }

    function openDetails(event, source) {
        currentEvent = event; opener = source;
        document.getElementById('modal-event-date').textContent = format(event.start, {weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'});
        document.getElementById('modal-event-title').textContent = event.title;
        document.getElementById('modal-event-time').textContent = format(event.start, {hour: '2-digit', minute: '2-digit'});
        document.getElementById('modal-event-duration').textContent = duration(event.duration_minutes);
        document.getElementById('modal-event-desc').textContent = event.description || 'Описание события пока не добавлено.';
        const items = document.getElementById('modal-playlist-content'); items.replaceChildren();
        if (event.use_playlist == 1) Object.values(playlist).filter(story => story && Array.isArray(story.titles)).forEach(story => story.titles.forEach(title => items.append(el('li', '', title))));
        document.getElementById('modal-playlist-container').hidden = !items.childNodes.length;
        if (modal.hidden) {previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden';}
        modal.hidden = false;
        dialog.querySelector('button').focus();
    }
    function closeDetails() {
        if (modal.hidden) return;
        modal.hidden = true; currentEvent = null; document.body.style.overflow = previousOverflow;
        (opener?.isConnected ? opener : document.getElementById('schedule-today')).focus({preventScroll: true});
    }

    function generateICS() {
        if (!currentEvent) return;
        const event = currentEvent;
        const stamp = date => date.toISOString().replace(/[-:]/g, '').slice(0, 15) + 'Z';
        const escape = text => String(text || '').replace(/\\/g, '\\\\').replace(/\r?\n|\r/g, '\\n').replace(/;/g, '\\;').replace(/,/g, '\\,');
        const lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//MLP Evening//Schedule//RU', 'BEGIN:VEVENT', `UID:${event.key.replace(':', '-')}@mlpevening`, `DTSTAMP:${stamp(new Date(now()))}`, `DTSTART:${stamp(event.start)}`, `DTEND:${stamp(new Date(event.start.getTime() + Number(event.duration_minutes) * 60000))}`, `SUMMARY:${escape(event.title)}`, `DESCRIPTION:${escape(event.description)}`, 'END:VEVENT', 'END:VCALENDAR'];
        const fold = line => {let output = '', bytes = 0; for (const c of line) {const size = new TextEncoder().encode(c).length; if (bytes + size > 75) {output += '\r\n '; bytes = 1;} output += c; bytes += size;} return output;};
        const url = URL.createObjectURL(new Blob([lines.map(fold).join('\r\n') + '\r\n'], {type: 'text/calendar;charset=utf-8'}));
        const link = el('a'); link.href = url; link.download = `event_${event.id}.ics`; document.body.append(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 15000);
    }

    root.querySelectorAll('[data-month-step]').forEach(button => button.onclick = () => {month = new Date(Date.UTC(month.getUTCFullYear(), month.getUTCMonth() + Number(button.dataset.monthStep), 1)); renderMonth();});
    document.getElementById('schedule-today').onclick = () => {month = currentMonth(); renderMonth();};
    document.getElementById('schedule-export').onclick = generateICS;
    modal.querySelector('.schedule-modal-close').onclick = closeDetails;
    modal.onclick = event => {if (event.target === modal) closeDetails();};
    modal.addEventListener('keydown', event => {
        if (event.key === 'Escape') {event.preventDefault(); closeDetails();}
        if (event.key === 'Tab') {const buttons = [...dialog.querySelectorAll('button')]; const first = buttons[0], last = buttons[buttons.length - 1]; if (event.shiftKey && document.activeElement === first) {event.preventDefault(); last.focus();} else if (!event.shiftKey && document.activeElement === last) {event.preventDefault(); first.focus();}}
    });
    renderMonth();
    fetchEvents();
})();
