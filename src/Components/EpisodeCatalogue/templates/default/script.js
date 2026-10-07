(function () {
    'use strict';
    const labels = { stale: 'устарело', low_votes: 'мало оценок', unknown: 'данные отсутствуют' };
    function element(tag, text, attrs = {}) {
        const node = document.createElement(tag);
        if (text !== null) node.textContent = String(text);
        Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
        return node;
    }
    function value(number) { return number === null ? '—' : String(number); }
    function canonical(a, b) {
        if (a.season === null && b.season !== null) return 1;
        if (a.season !== null && b.season === null) return -1;
        return (a.season || 0) - (b.season || 0) || (a.episode || 0) - (b.episode || 0) || a.id - b.id;
    }
    function compareCode(a, b, descending) {
        if (a.code === null && b.code !== null) return 1;
        if (a.code !== null && b.code === null) return -1;
        const delta = (a.season || 0) - (b.season || 0) || (a.episode || 0) - (b.episode || 0);
        return (descending ? -delta : delta) || a.id - b.id;
    }
    function compare(a, b, key, descending) {
        if (key === 'code') return compareCode(a, b, descending);
        const x = key === 'score' || key === 'sd' ? a.rating[key] : a[key];
        const y = key === 'score' || key === 'sd' ? b.rating[key] : b[key];
        if (x === null && y !== null) return 1;
        if (x !== null && y === null) return -1;
        const delta = x === null ? 0 : (typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'ru'));
        return (descending ? -delta : delta) || canonical(a, b);
    }
    function searchText(text) { return String(text).toLocaleLowerCase('ru').replace(/с(?=\d)/g, 's').replace(/е(?=\d)/g, 'e').trim(); }
    function matches(row, query, season) {
        if (season === 'special' && row.season !== null) return false;
        if (season && season !== 'special' && row.season !== Number(season)) return false;
        if (!query) return true;
        const code = query.match(/^s0*(\d+)\s*e0*(\d+)$/i);
        if (code) return row.season === Number(code[1]) && row.episode === Number(code[2]);
        return searchText(row.title).includes(query) || searchText(row.code || '').includes(query) || String(row.id) === query;
    }
    function uuid() {
        if (crypto.randomUUID) return crypto.randomUUID();
        const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }
    function details(row, open) {
        const node = element('details', null); node.open = open;
        node.append(element('summary', row.title));
        const box = element('div', null, { class: 'episode-catalogue-details' });
        box.append(element('p', `Количество оценок: ${value(row.rating.votes)}. Наблюдение (UTC): ${row.rating.observed_at || 'дата неизвестна'}.`));
        if (row.rating.imdb_url) box.append(element('a', 'Страница IMDb', { href: row.rating.imdb_url, target: '_blank', rel: 'noopener noreferrer' }));
        if (row.related) {
            const part = element('p', null);
            part.append(element('a', `Связанная часть: ${row.related.code || row.related.title}`, { href: `#episode-${row.related.id}`, 'data-catalogue-related': row.related.id })); box.append(part);
        }
        box.append(histogram(row.rating)); node.append(box); return node;
    }
    function histogram(rating) {
        if (rating.histogram === null) return element('p', 'Распределение голосов отсутствует.');
        const list = element('ol', null, { class: 'episode-catalogue-histogram', 'aria-label': 'Распределение голосов от 1 до 10' });
        rating.histogram.forEach((count, index) => {
            const item = element('li', null); item.append(element('span', `${index + 1}: ${count}`), element('meter', null, { min: 0, max: Math.max(1, rating.votes), value: count, 'aria-label': `Оценка ${index + 1}: ${count} голосов` })); list.append(item);
        });
        return list;
    }
    function quotaNumbers(input) {
        return input && input.limit === 3 && Number.isSafeInteger(input.used) && input.used >= 0 && input.remaining === Math.max(0, 3 - input.used) && input.timezone === 'Europe/Kaliningrad';
    }
    function quotaISO(text) {
        return typeof text === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(text) && Number.isFinite(Date.parse(text)) && new Date(text).toISOString() === text.replace('Z', '.000Z');
    }
    function quotaLocal(date) {
        const formatter = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Kaliningrad', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
        return Object.fromEntries(formatter.formatToParts(new Date(date)).map(p => [p.type, p.value]));
    }
    function quotaCalendar(input) {
        const observed = quotaLocal(input.observed_at), reset = quotaLocal(input.resets_at);
        const next = new Date(`${input.day}T00:00:00Z`); next.setUTCDate(next.getUTCDate() + 1);
        return `${observed.year}-${observed.month}-${observed.day}` === input.day && `${reset.year}-${reset.month}-${reset.day}` === next.toISOString().slice(0, 10) && reset.hour === '00' && reset.minute === '00' && reset.second === '00';
    }
    function parseQuota(input) {
        if (!quotaNumbers(input)) return null;
        if (!quotaISO(input.observed_at) || !quotaISO(input.resets_at) || Date.parse(input.resets_at) <= Date.parse(input.observed_at) || !/^\d{4}-\d{2}-\d{2}$/.test(input.day)) return null;
        return quotaCalendar(input) ? { ...input } : null;
    }
    function expireQuota(state) {
        if (state.quota && performance.now() >= state.quotaDeadline) { state.quota = null; clearTimeout(state.quotaTimer); }
    }
    function armQuota(state) {
        clearTimeout(state.quotaTimer);
        if (!state.quota) return;
        state.quotaTimer = setTimeout(() => { expireQuota(state); render(state); armQuota(state); }, Math.min(2147483647, Math.max(1, state.quotaDeadline - performance.now())));
    }
    function mergeQuota(state, quota) {
        if (!quota) { state.quota = null; clearTimeout(state.quotaTimer); return; }
        if (state.quotaDay && quota.day < state.quotaDay) return;
        const now = performance.now();
        const serverNow = state.serverEpoch + (now - state.serverMono);
        const deadline = now + Math.min(Date.parse(quota.resets_at) - Date.parse(quota.observed_at), Date.parse(quota.resets_at) - serverNow);
        const sameDay = state.quotaDay === quota.day;
        const used = sameDay ? Math.max(state.quotaUsed, quota.used) : quota.used;
        state.quotaDay = quota.day; state.quotaUsed = used;
        state.quotaDeadline = sameDay ? Math.min(state.quotaDeadline, deadline) : deadline;
        state.quota = deadline > now && state.quotaDeadline > now ? { ...quota, used, remaining: Math.max(0, 3 - used) } : null;
        armQuota(state);
    }
    function quotaText(state) {
        expireQuota(state);
        if (!state.authenticated) return '';
        if (!state.quota) return 'Остаток дневного лимита неизвестен; действие проверит сервер.';
        return state.quota.remaining === 0 ? 'Дневной лимит исчерпан. Новые пожелания доступны после следующей полуночи по Калининграду; отмена доступна.' : `Осталось сегодня: ${state.quota.remaining} из ${state.quota.limit}.`;
    }
    function actionButton(row, state, pending) {
        if (!state.authenticated) return element('a', 'Войти, чтобы пожелать', { href: '/login.php?redirect=%2Fepisodes.php', 'data-catalogue-login': '' });
        const exhausted = !pending && !row.own_active && state.quota?.remaining === 0;
        const text = exhausted ? 'Лимит на сегодня' : pending ? (pending.busy ? 'Выполняется…' : 'Повторить запрос') : (row.own_active ? 'Отменить желание' : 'Хочу посмотреть');
        const button = element('button', text, { type: 'button', 'data-catalogue-action': row.id }); button.disabled = !!pending?.busy || exhausted; if (exhausted) { button.title = 'Дневной лимит исчерпан. Можно отменить своё желание или дождаться следующего дня.'; button.dataset.quotaDisabled = 'true'; } return button;
    }
    function rowNode(row, state) {
        const node = element('tr', null, { id: `episode-${row.id}`, 'data-episode-id': row.id });
        const title = element('td', null); title.append(details(row, state.open.has(row.id)));
        const score = element('td', value(row.rating.score));
        if (labels[row.rating.status]) score.append(element('small', labels[row.rating.status]));
        const action = element('td', null); action.append(actionButton(row, state, state.pending.get(row.id)));
        node.append(element('td', row.code || 'Спецвыпуск'), title, element('td', row.views), element('td', row.wishes), score, element('td', (row.rating.sd === null ? '—' : String(Number(row.rating.sd.toFixed(3))))), action);
        if (state.admin) node.append(element('td', row.id), element('td', value(row.admin.two_part_id)), element('td', row.admin.length));
        return node;
    }
    function render(state) {
        expireQuota(state); state.root.querySelector('[data-catalogue-quota]').textContent = quotaText(state);
        const key = state.sort.value;
        const rows = Array.from(state.rows.values()).filter(row => matches(row, searchText(state.search.value), state.season.value));
        rows.sort((a, b) => compare(a, b, key, state.descending));
        state.body.replaceChildren(...rows.map(row => rowNode(row, state)));
        state.root.querySelector('[data-catalogue-count]').textContent = `Показано ${rows.length} из ${state.rows.size} эпизодов`;
        state.root.querySelector('[data-catalogue-empty]').hidden = rows.length !== 0;
        state.direction.textContent = state.descending ? 'По убыванию' : 'По возрастанию';
        state.root.querySelectorAll('[data-catalogue-column]').forEach(th => th.setAttribute('aria-sort', th.dataset.catalogueColumn === key ? (state.descending ? 'descending' : 'ascending') : 'none'));
    }
    function feedback(state, text, error = false, notify = true) {
        const node = state.root.querySelector('[data-catalogue-feedback]'); node.textContent = text; node.dataset.error = String(error);
        if (notify && typeof window.showFlashMessage === 'function') {
            const escaped = element('div', text).innerHTML; window.showFlashMessage(escaped, error ? 'error' : 'success');
            document.querySelector('.flash-message')?.classList.add('episode-catalogue-flash');
        }
    }
    function outcomeText(outcome, row, state) {
        if (!row) return 'Эпизод больше не доступен.';
        if (outcome.code === 'accepted') return row.own_active ? `Желание принято. ${quotaText(state)}` : 'Операция ранее принята; сейчас желание не активно.';
        if (outcome.code === 'cancelled') return row.own_active ? 'Отмена ранее выполнена; сейчас желание активно.' : 'Желание отменено. Лимит и срок повторного желания сохраняются.';
        const text = { daily_limit: 'Дневной лимит исчерпан.', cooldown: `Повторное желание доступно после ${outcome.facts.next_allowed_at || 'окончания срока ожидания'} (UTC).`, not_active: 'Активного желания уже нет.', missing: 'Эпизод не найден.' };
        return text[outcome.code] || 'Действие не выполнено.';
    }
    async function sendAction(state, id) {
        expireQuota(state); const row = state.rows.get(id); if (!row || state.pending.get(id)?.busy) return;
        if (!state.pending.has(id) && !row.own_active && state.quota?.remaining === 0) { render(state); feedback(state, 'Дневной лимит исчерпан.', true); return; }
        const request = state.pending.get(id) || { token: uuid(), action: row.own_active ? 'catalogue_cancel_wish' : 'catalogue_wish' };
        request.busy = true; state.pending.set(id, request); render(state); feedback(state, 'Выполняется…', false, false);
        const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch('/api.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: request.action, episode_id: id, operation_token: request.token, csrf_token: window.csrfToken || '' }), signal: controller.signal });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Действие не выполнено.');
            acceptResult(state, id, result.data);
        } catch (error) {
            request.busy = false; feedback(state, error.name === 'AbortError' ? 'Ответ не получен. Повтори тот же запрос.' : error.message, true);
        } finally { clearTimeout(timeout); render(state); restoreFocus(state, id); }
    }
    function restoreFocus(state, id) {
        if (document.activeElement !== document.body) return;
        let target = state.root.querySelector(`[data-catalogue-action="${id}"]`);
        if (!target || target.disabled) { target = state.root.querySelector(`[data-episode-id="${id}"]`) || state.root.querySelector('[data-catalogue-feedback]'); target.setAttribute('tabindex', '-1'); }
        target.focus({ preventScroll: true });
    }
    function validOutcome(outcome) {
        if (!outcome || !['accepted', 'rejected', 'cancelled'].includes(outcome.status) || !['accepted', 'cancelled', 'daily_limit', 'cooldown', 'not_active', 'missing'].includes(outcome.code) || !outcome.facts) return false;
        return outcome.code !== 'accepted' || (Number.isInteger(outcome.facts.quota_remaining) && outcome.facts.quota_remaining >= 0 && outcome.facts.quota_remaining <= 3);
    }
    function acceptResult(state, id, data) {
        if (!validOutcome(data?.outcome) || (data.row !== null && data.row?.id !== id)) throw new Error('Не удалось подтвердить состояние. Повтори запрос.');
        const provided = Object.prototype.hasOwnProperty.call(data, 'quota'); const quota = parseQuota(data.quota);
        if (provided && !quota) throw new Error('Не удалось подтвердить дневной лимит. Повтори тот же запрос.');
        mergeQuota(state, quota);
        const old = state.rows.get(id); state.pending.delete(id);
        if (data.row === null) state.rows.delete(id);
        else state.rows.set(id, state.admin && !data.row.admin ? { ...data.row, admin: old.admin } : data.row);
        feedback(state, outcomeText(data.outcome, data.row, state), data.outcome.status === 'rejected');
    }
    function reset(state) { state.search.value = ''; state.season.value = ''; state.sort.value = 'code'; state.descending = false; render(state); }
    function click(state, event) {
        const target = event.target.closest('button,a'); if (!target) return;
        if (target.hasAttribute('data-catalogue-login') && typeof window.openLoginModal === 'function') { event.preventDefault(); window.openLoginModal(event); }
        if (target.dataset.catalogueAction) sendAction(state, Number(target.dataset.catalogueAction));
        if (target.hasAttribute('data-catalogue-reset')) reset(state);
        if (target.hasAttribute('data-catalogue-direction')) { state.descending = !state.descending; render(state); }
        if (target.dataset.catalogueSortKey) { state.descending = state.sort.value === target.dataset.catalogueSortKey && !state.descending; state.sort.value = target.dataset.catalogueSortKey; render(state); }
        if (target.dataset.catalogueRelated) { state.search.value = ''; state.season.value = ''; render(state); }
    }
    function mount(root) {
        const payload = root.querySelector('.episode-catalogue-data'); if (!payload || root.dataset.catalogueMounted) return;
        root.dataset.catalogueMounted = 'true'; const data = JSON.parse(payload.textContent);
        const state = { root, admin: data.admin, authenticated: data.catalogue.viewer.authenticated, rows: new Map(data.catalogue.rows.map(row => [row.id, row])), pending: new Map(), open: new Set(), descending: false,
            quota: null, quotaDay: '', quotaUsed: 0, quotaDeadline: Infinity, quotaTimer: null, serverEpoch: (window.serverTime || 0) * 1000, serverMono: performance.now(),
            body: root.querySelector('tbody'), search: root.querySelector('[data-catalogue-search]'), season: root.querySelector('[data-catalogue-season]'), sort: root.querySelector('[data-catalogue-sort]'), direction: root.querySelector('[data-catalogue-direction]') };
        state.search.addEventListener('input', () => render(state)); state.season.addEventListener('change', () => render(state)); state.sort.addEventListener('change', () => render(state));
        root.addEventListener('click', event => click(state, event));
        root.addEventListener('toggle', event => { const row = event.target.closest('[data-episode-id]'); if (event.target.tagName !== 'DETAILS' || !row) return; const id = Number(row.dataset.episodeId); if (event.target.open) state.open.add(id); else state.open.delete(id); }, true);
        mergeQuota(state, parseQuota(data.catalogue.viewer.quota));
        document.addEventListener('visibilitychange', () => { if (!document.hidden) render(state); });
        render(state);
    }
    function start() { document.querySelectorAll('.episode-catalogue').forEach(mount); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
