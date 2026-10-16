<?php
$accountPrivacy = \Domain\Privacy::account(\Domain\Auth::userId());
$accountActive = ($accountPrivacy['state'] ?? '') === 'active' && ($accountPrivacy['policy_version'] ?? '') === \Domain\Privacy::version();
$accountExpired=($accountPrivacy['state']??'')==='withdrawn' && strtotime($accountPrivacy['delete_after'].' UTC')<=time();
?>
<section class="account-privacy" id="privacy">
    <h4>Данные аккаунта</h4>
    <p>В профиле хранятся имя, email, настройки и привязки входа; в сервисе — сообщения, голоса и другие действия. Реплики чата могут обрабатываться Лирой с помощью внешних моделей. <a href="/privacy.php#account-consent">Условия отдельного согласия</a>.</p>
    <?php if ($accountActive): ?>
        <p>Согласие действует. Необязательная статистика настраивается отдельно и не влияет на активность аккаунта.</p>
        <button type="button" class="privacy-secondary" data-privacy-open>Настройки статистики и плеера</button>
        <p><button type="button" class="privacy-withdraw" data-account-consent="withdraw" data-version="<?= \Domain\Privacy::version() ?>">Отозвать согласие на данные аккаунта</button></p>
    <?php else: ?>
        <?php if (($accountPrivacy['state'] ?? '') === 'withdrawn'): ?>
            <p role="status"><strong>Учётная запись неактивна.</strong> Можно читать чат и расписание; доступны вход и изменения профиля до срока удаления. Удаление данных запланировано до <?= htmlspecialchars((new \DateTimeImmutable($accountPrivacy['delete_after'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i')) ?> МСК. <?php if(!$accountExpired): ?>Повторное согласие до этого срока восстановит активность.<?php endif; ?></p>
        <?php else: ?>
            <p>Чтобы пользоваться аккаунтом, нужно отдельно подтвердить согласие на обработку его данных. Для статистики и плеера разрешения не обязательны.</p>
        <?php endif; ?>
        <?php if($accountExpired): ?><p>Срок восстановления истёк. Учётная запись ожидает фонового удаления.</p><?php else: ?>
            <label class="privacy-option"><input type="checkbox" id="account-consent-check"><span>Даю <a href="/privacy.php#account-consent">согласие на обработку данных аккаунта</a>, версия <?= \Domain\Privacy::version() ?>.</span></label>
            <button type="button" class="btn-primary" data-account-consent="accept" data-version="<?= \Domain\Privacy::version() ?>">Подтвердить согласие на данные аккаунта</button>
        <?php endif; ?>
    <?php endif; ?>
</section>
