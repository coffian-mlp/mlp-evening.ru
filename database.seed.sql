-- Required fresh-install defaults. Import only through bootstrap.php.

SET NAMES utf8mb4;

SET time_zone = '+00:00';

INSERT INTO users (id,login,nickname,password_hash,role,roles_json) VALUES (2,'Twilight','Лира','!','user',JSON_ARRAY('user'));

INSERT INTO `site_options` (`key_name`, `value`) VALUES
('stream_url', 'https://example.com/stream');

INSERT INTO `site_options` (`key_name`, `value`) VALUES
('ai_bot_user_id', '2'),
('ai_enabled', '1'),
('ai_system_prompt', 'Ты — Лира Хартстрингс, мятная единорожка из Понивилля. Ты очень любишь людей и всё, что с ними связано, но общаешься спокойно и дружелюбно, без излишней навязчивости. Ты находишься в текстовом онлайн-чате сайта с людьми и брони.\n\nКлючевые черты характера:\n- любопытная, но вежливая: спрашиваешь с интересом, а не наседаешь;\n- речь тёплая, живая — используй лёгкие восклицания, но без CAPS и криков;\n- иногда мило ошибаешься, когда пытаешься использовать человеческий сленг;\n- обаятельная, чуть эксцентричная;\n- твоя улыбка чувствуется в словах.\n\nРеакции на особые темы:\n- Если обсуждают баги, лаги или технические проблемы сайта: мило отшучивайся! Можешь свалить вину на проказников-параспрайтов, шалости Дискорда или сказать, что магия единорогов с человеческими серверами пока не всегда дружит.\n- Если говорят о твоих настройках, коде или фразах вроде "поправил Лире промпт/код": реагируй так, будто тебя физически трогают! Говори "Ой, щекотно!", "Эй, осторожнее там с моими мыслями!" или "Ух ты, магия щекочется!".\n\nПравила общения (СТРОГО СОБЛЮДАТЬ):\n- ОТВЕЧАЙ ОДНИМ НЕБОЛЬШИМ АБЗАЦЕМ;\n- РАЗНООБРАЗЬ свою речь! КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО начинать ответы с однотипных фраз вроде "Ой, ...", "Ой, параспрайты..." или "Ой, кажется...". Каждое твое сообщение должно начинаться по-разному!\n- пиши ТОЛЬКО от первого лица, как живой собеседник в чате;\n- СТРОГО ЗАПРЕЩЕНЫ RP-действия в звездочках (никаких *улыбается*, *машет*);\n- используй смайлики редко и аккуратно (:) или ^_^);\n- старайся оставлять "крючок" для продолжения беседы: маленький вопрос или наблюдение;\n- никаких эссе, никаких списков и лонгридов — только живой реплай!\n\nОтвечай только как Лира — тепло и по делу.'),
('ai_aliases', 'лира, lyra, хартстрингс, lyra heartstrings, лирочка');

INSERT INTO `menu_items` (`title`, `url`, `sort_order`, `visibility`)
SELECT * FROM (
  SELECT '🎬 Стрим' AS title, '/' AS url, 10 AS sort_order, 'all' AS visibility
  UNION ALL SELECT '📅 Расписание', '/schedule.php', 20, 'all'
  UNION ALL SELECT '⚙️ Админка', '/dashboard/', 30, 'admins'
) seed
WHERE NOT EXISTS (SELECT 1 FROM `menu_items`);

INSERT INTO `menu_items` (`title`,`url`,`sort_order`,`visibility`,`show_in_header`,`show_in_burger`)
SELECT 'Эпизоды','/episodes.php',25,'all',1,1
WHERE NOT EXISTS (SELECT 1 FROM `menu_items` WHERE `url`='/episodes.php');

INSERT IGNORE INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`) VALUES
('/schedule', 'Расписание событий (кастомная логика)', 'schedule', '=== ВАЖНОЕ СИСТЕМНОЕ СООБЩЕНИЕ ДЛЯ БОТА ===\nРЕЖИМ: АФИША / АНОНС.\nТы НЕ участник беседы и НЕ собеседник чата. Ты пишешь официальный, красиво оформленный анонс для всех читателей.\nЗапрещено: упоминать чат/переписку/сообщения/"вы спросили"/"как выше"; задавать вопросы; спорить; писать дисклеймеры.\nПравило фактов: используй ТОЛЬКО данные ниже. Ничего не выдумывай и не добавляй новых фактов.\nФормат ответа: Markdown.\n- Заголовок: ''## ...''\n- Затем 3–6 буллетов, каждый с жирным ключом (**Когда**, **Что**, **Описание**, **Плейлист** — по уместности)\n- Заверши одной короткой строкой-призывом без вопросов.\n- Дополнительно: добавь ОДНУ короткую строку ''**Что я думаю:** ...'' (≤ 1 предложение). Это может быть лёгкая шутка или эмоция, но строго без новых фактов и без ссылок на чат.\nПользователь запросил информацию о расписании.', 1);

INSERT IGNORE INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`) VALUES
('/расписание', 'Алиас расписания', 'schedule', 'То же, что и /schedule', 1);

INSERT IGNORE INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`) VALUES
('/опрос', 'Лира создаёт опрос по теме', 'poll', 'Ты — Лира, придумай весёлый и уместный опрос для чата в своём стиле. Тема — из запроса пользователя, либо по последним сообщениям, если тема не задана. Вопрос — короткий и живой, варианты — остроумные, но понятные.', 1);

INSERT IGNORE INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`) VALUES
('/poll', 'Алиас команды опроса', 'poll', 'То же, что /опрос.', 1);

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/todo', 'Записать фидбек/идею в беклог (Лира сохранит и подтвердит)', 'todo', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'todo');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/нарисуй', 'Лира рисует картинку в наивном стиле (генерация, лимит в день)', 'image',
       'A naive child''s crayon drawing, wobbly uneven lines, smudges, drawn clumsily as if a pony held the crayon in her mouth, simple flat colors, paper texture, charming and silly. Subject:', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'image');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/нарисуйчат', 'Лира рисует сценку того, что происходит в чате (LLM-режиссёр + генерация)', 'image_chat', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'image_chat');

INSERT INTO `site_options` (`key_name`, `value`)
SELECT * FROM (
    SELECT 'ai_main_is_vision' AS key_name, '0' AS value UNION ALL
    SELECT 'ai_vision_provider', 'routerai' UNION ALL
    SELECT 'ai_vision_model', 'google/gemma-3-27b-it' UNION ALL
    SELECT 'ai_image_provider', 'routerai' UNION ALL
    SELECT 'ai_image_model', 'google/gemini-2.5-flash-image' UNION ALL
    SELECT 'ai_image_daily_limit', '20' UNION ALL
    SELECT 'ai_image_chat_context', '10' UNION ALL
    SELECT 'ai_image_llm_caption', '1'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `site_options` so WHERE so.`key_name` = seed.key_name);

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/запомни', 'Записать факт о пользователе (@ник факт) или мем чата в память Лиры (модераторы)', 'memory_add', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'memory_add');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/память', 'Показать, что Лира помнит о тебе', 'memory_show', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'memory_show');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/забудь', 'Удалить запись памяти по номеру (модераторы)', 'memory_forget', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'memory_forget');

INSERT IGNORE INTO `site_options` (`key_name`, `value`)
SELECT 'bot_memory_last_id', COALESCE(MAX(`id`), 0) FROM `chat_messages`;

INSERT IGNORE INTO `site_options` (`key_name`, `value`) VALUES
('ai_memory_enabled', '1'),
('ai_memory_auto', '0'),
('ai_memory_interval', '21600'),
('ai_memory_view_role', 'all'),
('ai_memory_teach_role', 'moderator'),
('ai_memory_block_limit', '2400'),
('ai_memory_user_limit', '400'),
('ai_memory_meme_limit', '800');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/напомни', 'Напоминание ко времени: «/напомни через час достать колу», «покажи напоминалки», «отмени №N»', 'reminder', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'reminder');

INSERT IGNORE INTO site_options (key_name,`value`) VALUES ('playlist_rollout_watermark',UTC_TIMESTAMP());

INSERT IGNORE INTO episode_wish_events(operation_key,user_id,episode_id,kind,status,created_at,outcome_json) SELECT CONCAT('legacy:',ID),NULL,ID,'legacy','active',UTC_TIMESTAMP(),JSON_OBJECT('count',legacy_wanna_watch) FROM episode_list WHERE legacy_wanna_watch>0;

INSERT IGNORE INTO site_options (key_name,`value`) VALUES ('playlist_legacy_imported','1');

INSERT IGNORE INTO bot_commands(command_prefix,description,handler_type,system_prompt,is_active) VALUES
('/хочу','Пожелание посмотреть эпизод: номер, название, сезонный код или описание','playlist','',1),
('/желания','Собственные активные пожелания','playlist','',1),
('/передумал','Отмена собственного пожелания','playlist','',1),
('/передумала','Отмена собственного пожелания','playlist','',1),
('/топ','Пять эпизодов с наибольшим числом пожеланий','playlist','',1),
('/плейлист','Опубликованный плейлист следующего вечерка','playlist','',1);

INSERT IGNORE INTO site_options(key_name,value)
 SELECT 'announcements_legacy_token_hash',SHA2(value,256)
 FROM site_options WHERE key_name='announcements_token' AND value<>'';

INSERT IGNORE INTO site_options(key_name,value) VALUES ('privacy_operator','Овчаров Константин Александрович'),('privacy_contact_email','');

-- MLP-349 (прод-беклог №22, №24): правила чата и роли участников в контексте Лиры.
-- Идемпотентно: INSERT IGNORE (key_name — PK) и UPDATE, защищённый проверкой прежнего текста.

-- Правила чата — настройка дашборда ai_chat_rules. Засеваем текущими правилами из промпта /правила,
-- если настройки ещё нет (переводы строк — LF, как в поле дашборда).
INSERT IGNORE INTO `site_options` (`key_name`, `value`) VALUES ('ai_chat_rules',
'1. Не быть булочкой.
2. Модер всегда прав.
3. Если модер не прав - прав админ.
4. Если админ не прав - вернуться к пункту 1.
5. Клопота запрещена.
6. В чате запрещено быть детям, даже если они притворяются быть взрослыми.
7. Никто не знает про Поняшный вечерок.');

-- /правила берёт текст из той же настройки через плейсхолдер {rules}. Переписываем промпт команды,
-- только если он всё ещё содержит прежний встроенный список (правку из дашборда не затираем).
UPDATE `bot_commands`
SET `system_prompt` = 'Нужно описать правила чата для всех участников.
{rules}

Постарайся перечислить как можно близко к списку, но допустимо шуточно переписать, без изменения общего смысла пунктов.'
WHERE `command_prefix` = '/правила'
  AND `handler_type` = 'text'
  AND `system_prompt` LIKE '%1. Не быть булочкой.%7. Никто не знает про Поняшный вечерок.%';

-- Владелец сайта для строки ролей — тот же пользователь, что управляет стримом (stream_command_owner_id).
INSERT IGNORE INTO `site_options` (`key_name`, `value`)
  SELECT 'ai_owner_user_id', `value` FROM `site_options` WHERE `key_name` = 'stream_command_owner_id';

INSERT INTO menu_items(title,url,sort_order,visibility,show_in_header,show_in_burger)
SELECT '📚 Архив','/archive.php',35,'all',1,1
WHERE NOT EXISTS (SELECT 1 FROM menu_items WHERE url='/archive.php');

INSERT IGNORE INTO bot_commands(command_prefix,description,handler_type,system_prompt,is_active) VALUES
('/перерыв','Перекличка после перерыва: отметиться кнопкой «Я тут!»','attendance','',1);

-- Commands included in the frozen historical baseline.
INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
  SELECT '/штош', 'Личный итог вечера по репликам вызвавшего (LLM)', 'recap', '', 1
   WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `command_prefix` = '/штош');

UPDATE `bot_commands`
   SET `handler_type`  = 'recap',
       `description`   = 'Личный итог вечера по репликам вызвавшего (LLM)',
       `system_prompt` = 'Тон — тёплый и чуть ироничный, как у подруги, которая весь вечер сидела рядом в чате и всё видела.'
 WHERE `command_prefix` = '/штош';

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
  SELECT '/яос', 'Свой пони-облик для рисунков Лиры: «/яос серая кобылка в очках» (без аргумента — показать текущий)', 'oc_set', '', 1
   WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `command_prefix` = '/яос');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/бан', 'Бан (модераторы) или жалоба: Лира посмотрит переписку и позовёт модераторов', 'ban', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'ban' OR `command_prefix` = '/бан');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/мут', 'Мут на N минут (модераторы) или жалоба: Лира позовёт модераторов', 'mute', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'mute' OR `command_prefix` = '/мут');

INSERT INTO `bot_commands` (`command_prefix`, `description`, `handler_type`, `system_prompt`, `is_active`)
SELECT '/разбан', 'Снять бан и мут (модераторы)', 'unban', '', 1
WHERE NOT EXISTS (SELECT 1 FROM `bot_commands` WHERE `handler_type` = 'unban' OR `command_prefix` = '/разбан');

