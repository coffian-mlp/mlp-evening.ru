<aside id="privacy-panel" class="privacy-panel" aria-label="Приватность" hidden>
    <div class="privacy-summary">
        <strong>Настроим приватность?</strong>
        <p>Для входа и работы сайта нужны служебные cookie и хранилище. Статистика и сторонний плеер — по твоему выбору. <a href="/privacy.php">Подробнее</a></p>
        <?php if ($arResult['account'] && !\Domain\Privacy::isActive(\Domain\Auth::userId())): ?><p>Для действий от имени аккаунта нужно <a href="/profile.php#privacy">отдельное согласие в профиле</a>.</p><?php endif; ?>
    </div>
    <div class="privacy-buttons">
        <button type="button" class="btn-primary" data-privacy-necessary>Только необходимые</button>
        <button type="button" class="btn-primary" data-privacy-all>Разрешить всё</button>
        <button type="button" class="privacy-secondary" data-privacy-open>Настроить</button>
        <a data-privacy-exit href="https://yandex.ru/images/search?text=%D0%B3%D1%80%D1%83%D1%81%D1%82%D0%BD%D0%B0%D1%8F%20%D0%9F%D0%B8%D0%BD%D0%BA%D0%B8%20%D0%9F%D0%B0%D0%B9" rel="noreferrer" referrerpolicy="no-referrer" class="privacy-exit">Отказаться и уйти ↗ <small>Яндекс.Картинки</small></a>
    </div>
</aside>
<button type="button" class="privacy-settings-link" data-privacy-open title="Настройки приватности">Приватность</button>
<dialog id="privacy-dialog" aria-labelledby="privacy-heading">
    <form method="dialog" class="privacy-dialog-close"><button aria-label="Закрыть">×</button></form>
    <h2 id="privacy-heading">Настройки приватности</h2>
    <p>Разрешения действуют в этом браузере. Их можно изменить в любое время. Отказ от статистики не ограничивает аккаунт.</p>
    <label class="privacy-option"><input type="checkbox" checked disabled><span><strong>Работа сайта</strong><small>Вход, защита запросов, сохранение выбора приватности. Выбранные тобой настройки темы и громкости сохраняются как функциональные предпочтения.</small></span></label>
    <label class="privacy-option"><input type="checkbox" id="privacy-analytics"><span><strong>Техническая статистика</strong><small>Посещения, браузер и система, укрупнённые размеры экрана, тема, масштаб шрифта и технические ошибки. Без email, текста сообщений и IP в статистике. Детальные данные — 30 дней, сводки — год.</small></span></label>
    <label class="privacy-option"><input type="checkbox" id="privacy-external"><span><strong>Сторонний плеер</strong><small>При загрузке плеера Twitch или другого выбранного сервисом провайдера браузер обращается к нему напрямую. Провайдер может собирать IP, данные браузера и cookie по своей политике. Без разрешения плеер не загружается.</small></span></label>
    <button type="button" class="btn-primary" data-privacy-save>Сохранить выбор</button>
    <p><a href="/privacy.php">Политика обработки данных</a><?php if ($arResult['account']): ?> · <a href="/profile.php#privacy">Данные аккаунта и отзыв согласия</a><?php endif; ?></p>
    <p>Выход отзывает разрешения и очищает настройки этого браузера. Для удаления аккаунта отзови согласие в профиле.</p>
    <p><a data-privacy-exit href="https://yandex.ru/images/search?text=%D0%B3%D1%80%D1%83%D1%81%D1%82%D0%BD%D0%B0%D1%8F%20%D0%9F%D0%B8%D0%BD%D0%BA%D0%B8%20%D0%9F%D0%B0%D0%B9" rel="noreferrer" referrerpolicy="no-referrer">Отказаться и уйти в Яндекс.Картинки ↗</a></p>
    <p id="privacy-error" role="alert"></p>
</dialog>
