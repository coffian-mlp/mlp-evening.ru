function openEventModal() {
    document.getElementById('event-form').reset();
    document.getElementById('event_id').value = '';
        document.getElementById('event-modal-title').textContent = 'Добавить событие (Время МСК)';
    toggleRecurrence();
    document.getElementById('event-modal').style.display = 'flex';
}

function closeEventModal() {
    document.getElementById('event-form').reset();
    document.getElementById('event-modal').style.display = 'none';
}

function toggleRecurrence() {
    const isChecked = document.getElementById('event_is_recurring').checked;
    document.getElementById('recurrence_options').style.display = isChecked ? 'block' : 'none';
}

function editEvent(eventData) {
    document.getElementById('event_id').value = eventData.id;
    document.getElementById('event_title').value = eventData.title;
    document.getElementById('event_description').value = eventData.description;
    
    // We already have MSK time from PHP!
    if (eventData.start_time_msk) {
        document.getElementById('event_start_time').value = eventData.start_time_msk.replace(' ', 'T');
    }
    
    document.getElementById('event_duration_minutes').value = eventData.duration_minutes;
    document.getElementById('event_is_recurring').checked = eventData.is_recurring == 1;
    document.getElementById('event_recurrence_rule').value = eventData.recurrence_rule || 'weekly';
    document.getElementById('event_use_playlist').checked = eventData.use_playlist == 1;
    document.getElementById('event_generate_new_playlist').checked = eventData.generate_new_playlist == 1;
    document.getElementById('event_color').value = eventData.color || '#6d2f8e';
    
    document.getElementById('event-modal-title').textContent = 'Редактировать событие (Время МСК)';
    toggleRecurrence();
    document.getElementById('event-modal').style.display = 'flex';
}

function saveEvent(e) {
    e.preventDefault();
    
    const localTimeVal = document.getElementById('event_start_time').value;
    if (!localTimeVal) {
        alert('Введите время начала!');
        return;
    }
    
    // Parse "YYYY-MM-DDTHH:mm" manually to avoid browser timezone quirks
    const [datePart, timePart] = localTimeVal.split('T');
    const [y, m, d] = datePart.split('-');
    const [h, min] = timePart.split(':');
    
    // Treat the input explicitly as MSK (UTC+3). We don't want browser timezone to interfere!
    // First, create an absolute UTC timestamp as if the user typed UTC time.
    const inputAsUtcMs = Date.UTC(parseInt(y), parseInt(m) - 1, parseInt(d), parseInt(h), parseInt(min), 0);
    
    // Then subtract 3 hours (in milliseconds) to convert this "fake UTC" back to true UTC.
    const trueUtcMs = inputAsUtcMs - (3 * 3600000);
    const utcDate = new Date(trueUtcMs);
    
    const utcYear = utcDate.getUTCFullYear();
    const utcMonth = String(utcDate.getUTCMonth() + 1).padStart(2, '0');
    const utcDay = String(utcDate.getUTCDate()).padStart(2, '0');
    const utcHours = String(utcDate.getUTCHours()).padStart(2, '0');
    const utcMins = String(utcDate.getUTCMinutes()).padStart(2, '0');
    const utcSecs = String(utcDate.getUTCSeconds()).padStart(2, '0');
    
    const utcTimeStr = `${utcYear}-${utcMonth}-${utcDay} ${utcHours}:${utcMins}:${utcSecs}`;
    
    const formData = new FormData();
    formData.append('action', 'save_event');
    formData.append('csrf_token', window.csrfToken || ''); // assumes csrfToken is globally available in dashboard
    
    formData.append('id', document.getElementById('event_id').value);
    formData.append('title', document.getElementById('event_title').value);
    formData.append('description', document.getElementById('event_description').value);
    formData.append('start_time_utc', utcTimeStr);
    formData.append('duration_minutes', document.getElementById('event_duration_minutes').value);
    formData.append('is_recurring', document.getElementById('event_is_recurring').checked ? '1' : '0');
    formData.append('recurrence_rule', document.getElementById('event_recurrence_rule').value);
    formData.append('use_playlist', document.getElementById('event_use_playlist').checked ? '1' : '0');
    formData.append('generate_new_playlist', document.getElementById('event_generate_new_playlist').checked ? '1' : '0');
    formData.append('color', document.getElementById('event_color').value);
    
    fetch('/api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            closeEventModal();
            location.reload();
        } else {
            alert(data.message || 'Ошибка сохранения');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Сетевая ошибка');
    });
}

function deleteEvent(id) {
    if (!confirm('Точно удалить это событие?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_event');
    formData.append('csrf_token', window.csrfToken || '');
    formData.append('id', id);
    
    fetch('/api.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Ошибка удаления');
        }
    })
    .catch(err => {
        console.error(err);
        alert('Сетевая ошибка');
    });
}
