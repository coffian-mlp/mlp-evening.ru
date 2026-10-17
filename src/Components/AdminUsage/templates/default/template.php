<section class="card usage-card" id="llm-usage" aria-labelledby="usage-title">
    <div class="usage-heading"><h3 id="usage-title">Потребление Лиры</h3><label>Период <select id="usage-days" class="form-input"><option value="7">7 дней</option><option value="30" selected>30 дней</option><option value="90">90 дней</option></select></label><button type="button" class="btn-secondary" id="usage-refresh">Обновить</button></div>
    <p>Каждое обращение к модели, включая повторы и неудачные попытки. Сбор начинается после обновления; подробности хранятся 90 дней.</p>
    <p id="usage-status" role="status">Загрузка…</p>
    <div id="usage-totals"></div>
    <div class="usage-scroll"><table class="admin-table"><thead><tr><th>Провайдер / модель</th><th>Задача</th><th>Попытки</th><th>Ошибки / обрывы</th><th>Токены: вход / выход</th><th>Без данных о токенах</th><th>Оценка затрат</th></tr></thead><tbody id="usage-rows"></tbody></table></div>
    <details class="usage-pricing"><summary>Настроить тарифы для оценки затрат</summary>
        <p>Цены ввода и вывода — за миллион токенов; для изображений можно задать цену одного запроса. Пустая цена означает «неизвестно», ноль — бесплатный тариф. Цены применяются только к новым запросам; скидки на кеш и дополнительные услуги не учитываются.</p>
        <form id="usage-rates-form"><div class="usage-scroll"><table class="admin-table"><thead><tr><th>Провайдер</th><th>Модель</th><th>Валюта</th><th>Ввод / 1 млн</th><th>Вывод / 1 млн</th><th>Изображение / запрос</th><th></th></tr></thead><tbody id="usage-rates"></tbody></table></div>
        <div class="usage-actions"><button type="button" class="btn-secondary" id="usage-add-rate">Добавить модель</button><button type="submit" class="btn-primary">Сохранить тарифы</button></div></form>
    </details>
</section>
