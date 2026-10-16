(function () {
    'use strict';
    if (window.ChatKeywordAlerts) return;
    const empty = () => ({enabled: false, words: []});
    const fold = text => String(text).normalize('NFC').toLocaleLowerCase('ru-RU').replace(/ё/g, 'е').replace(/\s+/gu, ' ').trim();
    function parse(raw) {
        try {
            const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
            if (!data || typeof data.enabled !== 'boolean' || !Array.isArray(data.words) || data.words.length > 10
                || data.words.some(w => typeof w !== 'string' || [...w].length > 50)) return empty();
            return {enabled: data.enabled, words: [...new Set(data.words.map(w => w.replace(/\s+/gu, ' ').trim()).filter(Boolean))]};
        } catch (_) { return empty(); }
    }
    // Bounded noun endings, not prefix stemming: derived words remain separate.
    // Phrases, short tokens and non-Russian keywords retain exact matching.
    function wordForms(word) {
        const forms = new Set([word]);
        if (!/^[а-я]{4,}$/u.test(word)) return [...forms];
        let stem = word, endings = [];
        if (word.endsWith('а')) {
            stem = word.slice(0, -1);
            endings = [/[гкхжчшщ]$/u.test(stem) ? 'и' : 'ы', 'е', 'у', 'ой', 'ою', 'ам', 'ами', 'ах'];
        } else if (word.endsWith('я')) {
            stem = word.slice(0, -1);
            endings = ['и', 'е', 'ю', 'ей', 'ею', 'ям', 'ями', 'ях'];
        } else if (/[йь]$/u.test(word)) {
            stem = word.slice(0, -1);
            endings = ['я', 'ю', 'ем', 'е', 'и', 'ей', 'ям', 'ями', 'ях'];
            if (word.endsWith('ь')) endings.push('ью');
        } else if (/[бвгджзклмнпрстфхцчшщ]$/u.test(word)) {
            endings = ['а', 'у', 'ом', 'е', /[гкхжчшщ]$/u.test(stem) ? 'и' : 'ы', 'ов', 'ам', 'ами', 'ах'];
        }
        if (stem.length >= 3) endings.forEach(ending => forms.add(stem + ending));
        return [...forms];
    }
    function matches(message, words) {
        // Decode stored entities as text; quoted history, attachments, URLs and code are not new speech.
        const decoder = document.createElement('textarea');
        decoder.innerHTML = String(message || '').replace(/<[^>]*>/g, ' ');
        const text = fold(decoder.value.replace(/```[\s\S]*?```/g, ' ').replace(/`[^`]*`/g, ' ')
            .replace(/^\s*>.*$/gm, ' ').replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
            .replace(/\[\[command[^\]]*\]\]/g, ' ').replace(/https?:\/\/\S+/gi, ' '));
        return words.some(word => {
            const normalized = fold(word);
            if (!normalized) return false;
            const escaped = wordForms(normalized).map(form => form.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|');
            return new RegExp('(^|[^\\p{L}\\p{N}_])(?:' + escaped + ')(?=$|[^\\p{L}\\p{N}_])', 'u').test(text);
        });
    }
    let owner = 0, prefs = empty(), audio = null, lastRing = 0, revision = 0;
    let afterId = Number(window.chatConfig?.notification_after_id || 0);
    const seen = new Set();
    function status(text) { $('#profile-keywords-status').text(text); }
    function render() {
        $('#profile-keywords-enabled').prop('checked', prefs.enabled);
        $('#profile-keywords').val(prefs.words.join(', '));
    }
    function configure(id, options) {
        id = Number(id || 0);
        if (id !== owner) {
            seen.clear(); lastRing = 0;
            document.querySelectorAll('.chat-message[data-id]').forEach(el => { afterId = Math.max(afterId, Number(el.dataset.id) || 0); });
        }
        revision++; owner = id; prefs = owner ? parse(options?.chat_keyword_alerts) : empty();
        render();
    }
    function chime() {
        if (!audio || audio.state !== 'running') return false;
        const start = audio.currentTime;
        [880, 1320].forEach((frequency, i) => {
            const oscillator = audio.createOscillator(), gain = audio.createGain();
            oscillator.type = 'sine'; oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(i ? 0.025 : 0.055, start + 0.012);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.65);
            oscillator.connect(gain); gain.connect(audio.destination);
            oscillator.start(start); oscillator.stop(start + 0.7);
        });
        return true;
    }
    function unlock(test = false) {
        const Audio = window.AudioContext || window.webkitAudioContext;
        if (!Audio) { if (test) status('Этот браузер не поддерживает звуковые уведомления.'); return Promise.resolve(false); }
        try {
            audio ??= new Audio();
            return Promise.resolve(audio.resume()).then(() => {
                const ready = audio.state === 'running';
                if (test) status(ready && chime() ? 'Так прозвучит уведомление.' : 'Браузер пока не разрешил звук. Нажми «Проверить звук» ещё раз.');
                return ready;
            }).catch(() => { if (test) status('Браузер не разрешил звук. Попробуй ещё раз.'); return false; });
        } catch (_) { if (test) status('Не удалось включить звук в браузере.'); return Promise.resolve(false); }
    }
    function claim(id) {
        const now = Date.now();
        if (now - lastRing < 5000) return;
        const key = 'mlp.keyword-alert.last.' + owner;
        try {
            const previous = JSON.parse(localStorage.getItem(key) || 'null');
            if (previous && (previous.id === id || now - previous.at < 5000)) return;
        } catch (_) {}
        if (!chime()) return;
        lastRing = now;
        try { localStorage.setItem(key, JSON.stringify({id, at: now})); } catch (_) {}
    }
    function notify(message) {
        const id = Number(message.id);
        if (!owner || !Number.isSafeInteger(id) || id <= afterId || seen.has(id)) return;
        seen.add(id); if (seen.size > 500) seen.delete(seen.values().next().value);
        if (!prefs.enabled || Number(message.user_id) === owner || Number(message.deleted || message.is_deleted || 0)
            || !matches(message.raw_message ?? message.message, prefs.words) || !audio || audio.state !== 'running') return;
        const generation = revision, account = owner;
        const deliver = () => { if (owner === account && revision === generation && prefs.enabled) claim(id); };
        if (navigator.locks?.request) {
            navigator.locks.request('mlp-keyword-alert-' + owner, {ifAvailable: true}, lock => { if (lock) deliver(); }).catch(() => {});
        } else deliver();
    }
    window.ChatKeywordAlerts = {notify, matches};
    $(function () {
        configure(window.currentUserId, window.userOptions);
        $(document).on('chat:user-options', (_, id, options) => configure(id, options));
        $(document).on('click keydown', event => {
            if (event.originalEvent?.isTrusted && prefs.enabled && (!audio || audio.state !== 'running')) unlock();
        });
        $('#profile-keywords-test').on('click', () => unlock(true));
        $('#profile-keywords-save').on('click', function () {
            const button = $(this), id = owner;
            const value = {enabled: $('#profile-keywords-enabled').prop('checked'), words: $('#profile-keywords').val().split(/[,;\n]/u).map(w => w.trim()).filter(Boolean)};
            if (value.enabled) unlock();
            button.prop('disabled', true);
            $.post('/api.php', {action: 'save_user_option', key: 'chat_keyword_alerts', value: JSON.stringify(value)}, null, 'json')
                .done(response => {
                    if (owner !== id) return;
                    if (!response.success) { window.showFlashMessage?.(response.message, 'error'); return; }
                    window.userOptions ??= {}; window.userOptions.chat_keyword_alerts = response.data.value;
                    revision++; prefs = parse(response.data.value); render();
                    try { localStorage.setItem('mlp.keyword-alert.settings.' + owner, JSON.stringify({value: response.data.value, at: Date.now()})); } catch (_) {}
                    window.showFlashMessage?.('Уведомления сохранены.', 'success');
                }).fail(() => window.showFlashMessage?.('Не удалось сохранить уведомления. Попробуй ещё раз.', 'error'))
                .always(() => button.prop('disabled', false));
        });
        window.addEventListener('storage', event => {
            if (event.key !== 'mlp.keyword-alert.settings.' + owner || !event.newValue) return;
            try { const update = JSON.parse(event.newValue); revision++; prefs = parse(update.value); render(); } catch (_) {}
        });
    });
})();
