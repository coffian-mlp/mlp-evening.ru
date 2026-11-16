/* The server owns validation, version checks and canonical story payloads. */
document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('playlist-editor');
    if (!root || !root.querySelector('[data-editor-state]')) return;
    const find = selector => root.querySelector(selector);
    let state = JSON.parse(find('[data-editor-state]').textContent);
    let replaceIndex = null;
    let busy = false;
    let searchSerial = 0;
    const status = (message, error = false) => {
        find('[data-editor-status]').textContent = message;
        find('[data-editor-status]').classList.toggle('playlist-editor-warning', error);
    };
    const button = (label, action, disabled = false) => {
        const el = document.createElement('button');
        el.type = 'button'; el.textContent = label; el.disabled = disabled || busy;
        el.addEventListener('click', action);
        return el;
    };
    async function request(action, params) {
        const response = await fetch('/api.php', {
            method: 'POST', credentials: 'same-origin',
            body: new URLSearchParams(Object.assign({action, csrf_token: document.querySelector('meta[name="csrf-token"]').content}, params))
        });
        const json = await response.json();
        if (!json.success) throw new Error(json.message || 'Не удалось сохранить плейлист.');
        return json.data;
    }
    function render() {
        const list = find('[data-editor-list]'); list.replaceChildren();
        state.stories.forEach((story, index) => {
            const li = document.createElement('li');
            const title = document.createElement('strong'); title.textContent = story.titles.join(' / ');
            const length = document.createElement('span'); length.textContent = ` — ${story.length} сл. (≈ ${story.length * 24} мин.)`;
            const controls = document.createElement('div'); controls.className = 'playlist-editor-actions';
            controls.append(
                button('Вверх', () => edit({operation: 'move', index, to_index: index - 1}), index === 0),
                button('Вниз', () => edit({operation: 'move', index, to_index: index + 1}), index === state.stories.length - 1),
                button('Заменить', () => search(index)),
                button('Удалить', () => edit({operation: 'remove', index}))
            );
            li.append(title, length, controls); list.append(li);
        });
        if (!state.stories.length) {
            const li = document.createElement('li'); li.textContent = 'Плейлист пуст. Добавь серию или фильм.'; list.append(li);
        }
        const length = find('[data-editor-duration]');
        length.textContent = `Занято ${state.used_slots} из ${state.capacity_slots} слотов: ≈ ${state.estimated_minutes} из ${state.duration_minutes} мин. (1 слот ≈ 24 мин.).` + (state.over_capacity ? ' Плейлист длиннее эфира.' : ' Плейлист влезает в эфир.');
        length.classList.toggle('playlist-editor-warning', state.over_capacity);
        find('[data-editor-announcement]').hidden = !state.announcement_prepared;
    }
    function closeSearch() {
        searchSerial++; find('[data-editor-search]').hidden = true;
        find('[data-editor-results]').replaceChildren();
    }
    function search(index) {
        if (busy) return;
        replaceIndex = index; searchSerial++;
        find('[data-editor-search]').hidden = false;
        find('[data-editor-search-label]').textContent = index === null ? 'Добавить: поиск по названию' : `Заменить пункт ${index + 1}: поиск по названию`;
        find('[data-editor-results]').replaceChildren();
        find('#playlist-story-query').value = ''; find('#playlist-story-query').focus();
    }
    async function edit(operation) {
        if (busy) return;
        busy = true; closeSearch();
        root.querySelectorAll('button, input').forEach(el => { el.disabled = true; });
        status('Сохраняем плейлист…');
        try {
            const data = await request('edit_upcoming_playlist', Object.assign({run_id: state.run_id, snapshot_id: state.snapshot_id, version: state.version}, operation));
            state = data.editor; status('Плейлист сохранён.');
        } catch (e) { status(e.message, true); }
        finally {
            busy = false;
            root.querySelectorAll('button, input').forEach(el => { el.disabled = false; });
            render();
        }
    }
    async function findStories() {
        if (busy) return;
        const query = find('#playlist-story-query').value.trim();
        const serial = ++searchSerial;
        const results = find('[data-editor-results]'); results.replaceChildren();
        if (!query) { status('Введи часть названия серии или фильма.'); return; }
        status('Ищем…');
        try {
            const data = await request('search_playlist_stories', {query});
            if (serial !== searchSerial) return;
            data.stories.forEach(story => {
                const li = document.createElement('li');
                const present = state.stories.some((item, index) => item.story_id === story.story_id && index !== replaceIndex);
                li.append(button(story.titles.join(' / ') + ` (${story.length} сл.)` + (present ? ' — уже в плейлисте' : ''), () => edit(Object.assign({operation: replaceIndex === null ? 'add' : 'replace', episode_id: story.ids[0]}, replaceIndex === null ? {} : {index: replaceIndex})), present));
                results.append(li);
            });
            status(data.stories.length ? 'Выбери историю. Будет сохранён весь пункт, включая обе части.' : 'Ничего не найдено.');
        } catch (e) { if (serial === searchSerial) status(e.message, true); }
    }
    find('[data-editor-add]').addEventListener('click', () => search(null));
    find('[data-editor-refresh]').addEventListener('click', () => location.reload());
    find('[data-editor-cancel]').addEventListener('click', closeSearch);
    find('[data-editor-find]').addEventListener('click', findStories);
    find('#playlist-story-query').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); findStories(); } });
    render();
});
