/** Shared server-authoritative command choices for both chat layouts/transports. */
(function () {
    'use strict';
    const widgets = new Set();
    const queue = [];
    let activeReads = 0;
    const visibility = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            entry.target._commandVisible = entry.isIntersecting;
            if (entry.isIntersecting && entry.target._commandState === 'pending') refresh(entry.target);
        });
    });
    const states = { consumed: 'Выбор завершён', cancelled: 'Выбор отменён', expired: 'Срок выбора истёк', unavailable: 'Выбор недоступен' };

    async function post(action, data) {
        const form = new URLSearchParams({ action, ...data });
        if (action === 'act_command_interaction') form.set('csrf_token', window.csrfToken || '');
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        let result;
        try {
            const response = await fetch('/api.php', { method: 'POST', credentials: 'same-origin', body: form, signal: controller.signal });
            result = await response.json();
        } finally { clearTimeout(timeout); }
        if (!result.success) throw new Error(result.message || 'Не удалось выполнить действие.');
        return result.data;
    }

    function render(widget, data) {
        widget.replaceChildren();
        widget._commandState = data.state;
        const status = document.createElement('span');
        status.className = 'command-interaction-status';
        status.textContent = states[data.state] || (data.can_act ? 'Выбери вариант:' : 'Выбор доступен автору команды');
        widget.append(status);
        if (data.state !== 'pending') return;
        const controls = document.createElement('div');
        controls.className = 'command-interaction-options';
        (data.options || []).forEach(option => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = option.label;
            button.dataset.optionKey = option.key;
            button.disabled = !data.can_act;
            button.addEventListener('click', async event => {
                event.preventDefault();
                event.stopPropagation();
                if (widget._commandSending) return;
                widget._commandSending = true;
                controls.querySelectorAll('button').forEach(item => { item.disabled = true; });
                status.textContent = 'Выполняется…';
                try {
                    await post('act_command_interaction', { interaction_id: widget.dataset.interactionId, option_key: option.key });
                    await refresh(widget);
                } catch (error) {
                    // The request may have committed before a network failure: always refetch first.
                    await refresh(widget);
                    if (widget._commandState === 'pending') {
                        const notice = document.createElement('span');
                        notice.className = 'command-interaction-error';
                        notice.textContent = error.message;
                        widget.append(notice);
                    }
                } finally { widget._commandSending = false; }
            });
            controls.append(button);
        });
        widget.append(controls);
    }

    async function refresh(widget) {
        if (widget._commandLoading || !widget.isConnected) return;
        widget._commandLoading = true;
        if (activeReads >= 2) await new Promise(resolve => queue.push(resolve));
        else activeReads++;
        try {
            if (!widget.isConnected) return;
            const data = await post('get_command_interaction', {
                interaction_id: widget.dataset.interactionId, message_id: widget._commandMessageId,
            });
            if (Number(data.message_id) !== Number(widget._commandMessageId)) {
                widget._commandState = 'unavailable';
                widget.textContent = '[Выбор команды недоступен]';
                return;
            }
            render(widget, data);
        } catch (error) {
            widget.textContent = 'Не удалось загрузить выбор. Повторим загрузку.';
            widget._commandState = 'pending';
        } finally {
            widget._commandLoading = false;
            if (queue.length) queue.shift()();
            else activeReads--;
        }
    }

    function mount(widget) {
        if (widget._commandMounted) return;
        const message = widget.closest('.chat-message[data-id]');
        if (!message || widget.closest('.chat-quote, .quoted-message, #chat-pinned-banner')) return;
        widget._commandMounted = true;
        widget._commandMessageId = message.dataset.id;
        widget._commandState = 'pending';
        widget.textContent = 'Загрузка выбора…';
        widgets.add(widget);
        visibility.observe(widget);
    }

    function scan() { document.querySelectorAll('.command-interaction[data-interaction-id]').forEach(mount); }
    function start() {
        scan();
        new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
        setInterval(() => {
            widgets.forEach(widget => {
                if (!widget.isConnected) { widgets.delete(widget); visibility.unobserve(widget); return; }
                if (widget._commandState === 'pending' && !widget._commandSending && widget._commandVisible) refresh(widget);
            });
        }, 5000);
    }
    window.CommandInteractions = { mount, refresh };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
