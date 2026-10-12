<?php
$settings=\Domain\AnnouncementSettings::values();
$escape=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$tz=new \DateTimeZone($settings['announcements_timezone']);
$when=static fn($v)=>(new \DateTimeImmutable(is_int($v)?'@'.$v:$v,new \DateTimeZone('UTC')))->setTimezone($tz)->format('d.m.Y H:i');
$labels=['text_pending'=>'В очереди: текст','text_generating'=>'Генерируется текст','image_pending'=>'В очереди: изображение','image_generating'=>'Генерируется изображение','preview_pending'=>'Готов, ожидает отправки в личку','preview_sending'=>'Отправляется в личку','controls_pending'=>'Ожидает кнопок в Telegram','controls_sending'=>'Отправляются кнопки','pending'=>'Ожидает одобрения','approved'=>'Одобрен, запланирован','published'=>'Опубликован','cancelled'=>'Отменён','expired'=>'Срок истёк','uncertain'=>'Доставку нужно проверить','failed'=>'Ошибка','feedback_text'=>'Ожидает замечаний к тексту','feedback_image'=>'Ожидает замечаний к изображению','feedback_both'=>'Ожидает замечаний','sending'=>'Публикуется'];
?>
<section class="card announcement-overview">
    <h2 class="dashboard-title">Анонсы</h2>
    <p>Сейчас доступна одна еженедельная серия анонсов: основной пост и напоминание для выбранного события. Текст, изображение и одобрение общие для дашборда и Telegram.</p>
    <?php if (!empty($arResult['upcoming'])): $up=$arResult['upcoming']; ?>
        <h3><?= $escape($up['event']['title']) ?> — <?= $escape($when((int)$up['event']['real_start_time'])) ?></h3>
        <dl class="announcement-timeline">
            <?php foreach (['prepare_at'=>'Подготовка черновиков','main_at'=>'Основной пост','reminder_at'=>'Напоминание'] as $key=>$label): ?>
                <div><dt><?= $label ?></dt><dd><?= $escape($when((int)$up['schedule'][$key])) ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <p>Часовой пояс: <?= $escape($settings['announcements_timezone']) ?>. Дата и время показа берутся из <a href="/dashboard/#tab-events">календаря</a>, плейлист — из снимка, закреплённого за этим показом.</p>
        <?php if ($settings['announcements_enabled']==='1'): ?>
            <form class="announcement-action-form" method="post" action="/api.php">
                <input type="hidden" name="action" value="announcement_post_action">
                <button class="btn btn-primary" type="submit" name="operation" value="prepare">Подготовить ближайший вечерок</button>
            </form>
            <p>Можно подготовить раньше срока. Если посты уже созданы, кнопка не меняет тексты и не отправляет дубликаты.</p>
        <?php endif; ?>
    <?php else: ?>
        <p><?= $escape($arResult['scheduleError']??'Выбери активное еженедельное событие с плейлистом в настройках ниже.') ?></p>
    <?php endif; ?>
    <p><button type="button" class="btn btn-secondary announcement-refresh">Обновить статусы</button> <button type="button" class="btn btn-secondary announcement-go-settings">Настройки</button></p>
</section>
<section class="card" id="announcement-posts">
    <h3 class="dashboard-title">Посты и согласование</h3>
    <p>Одобрение назначает публикацию на указанное время. Если оно уже наступило, воркер опубликует пост при ближайшей проверке. Редактура и перегенерация снимают прежнее одобрение.</p>
    <?php if (!$arResult['posts']): ?><p>Постов пока нет. Они появятся после подготовки ближайшего события.</p><?php endif; ?>
    <?php foreach ($arResult['posts'] as $r):
        $editable=in_array($r['state'],['pending','approved','preview_pending','failed','feedback_text','feedback_image','feedback_both'],true) && time()<strtotime($r['expires_at'].' UTC');
        $cancelable=$editable || (in_array($r['state'],['text_pending','image_pending','controls_pending'],true) && time()<strtotime($r['expires_at'].' UTC'));
        $restorable=$r['state']==='cancelled' && time()<strtotime($r['expires_at'].' UTC');
        $ready=$editable && $r['caption'] && $r['image_path'] && in_array($r['state'],['pending','preview_pending','failed'],true);
    ?>
        <article class="announcement-post" data-revision="<?= (int)$r['id'] ?>">
            <h4>#<?= (int)$r['stream_number'] ?> · <?= $r['kind']==='main'?'Основной анонс':'Напоминание' ?> · версия <?= (int)$r['version'] ?></h4>
            <p><strong><?= $escape($labels[$r['state']]??$r['state']) ?></strong> · публикация <?= $escape($when($r['due_at'])) ?> · показ <?= $escape($when($r['start_at'])) ?></p>
            <p>Публикация доступна до <?= $escape($when($r['expires_at'])) ?> · <?= $escape($settings['announcements_timezone']) ?></p>
            <?php if ($r['state']==='uncertain'): ?><p class="announcement-warning">Telegram не подтвердил доставку. Автоматического повтора нет. Проверь личку и канал, затем используй /help в боте для подтверждения доставки.</p><?php endif; ?>
            <?php if ($r['last_error']): ?><p>Последняя ошибка: <?= $escape($r['last_error']) ?><?php if ($r['retry_at']): ?>. Следующая попытка: <?= $escape($when($r['retry_at'])) ?><?php endif; ?></p><?php endif; ?>
            <div class="announcement-preview">
                <?php if ($r['image_path'] && preg_match('~\A/upload/lyra/[A-Za-z0-9_-]+\.jpg\z~D',$r['image_path'])): ?><img src="<?= $escape($r['image_path']) ?>" alt="Изображение анонса" loading="lazy"><?php endif; ?>
                <div class="announcement-caption"><?= $escape($r['caption']??'Текст ещё не подготовлен.') ?></div>
            </div>
            <?php if ($editable || $cancelable || $restorable): ?>
                <form class="announcement-action-form" method="post" action="/api.php">
                    <input type="hidden" name="action" value="announcement_post_action">
                    <input type="hidden" name="revision_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="nonce" value="<?= $escape($r['nonce']) ?>">
                    <div class="announcement-actions">
                        <?php if ($ready): ?><button type="submit" class="btn btn-primary" name="operation" value="approve">Одобрить публикацию</button><?php endif; ?>
                        <?php if ($restorable): ?><button type="submit" class="btn btn-secondary" name="operation" value="both">Создать черновик заново</button><?php else: ?><button type="submit" class="btn btn-secondary" name="operation" value="cancel">Отменить пост</button><?php endif; ?>
                    </div>
                    <?php if ($editable): ?><details class="announcement-details">
                        <summary>Редактирование и перегенерация <span aria-hidden="true">▾</span></summary>
                        <label class="form-label">Текст поста<textarea name="caption" class="form-input" rows="8"><?= $escape($r['caption']??'') ?></textarea></label>
                        <p>Дата и время показа по Москве, ссылка на сайт и лимит подписи Telegram проверяются при сохранении. Номер добавится автоматически.</p>
                        <button type="submit" class="btn btn-secondary" name="operation" value="edit">Сохранить новую версию текста</button>
                        <label class="form-label">Замечания для модели<textarea name="feedback" class="form-input" rows="3" maxlength="2000" placeholder="Что изменить в следующем варианте"></textarea></label>
                        <div class="announcement-actions">
                            <button type="submit" class="btn btn-secondary" name="operation" value="text">Переделать текст</button>
                            <button type="submit" class="btn btn-secondary" name="operation" value="image">Переделать картинку</button>
                            <button type="submit" class="btn btn-secondary" name="operation" value="both">Переделать оба</button>
                        </div>
                    </details><?php endif; ?>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php include dirname(__DIR__,3).'/AdminSettings/templates/announcements/template.php'; ?>
