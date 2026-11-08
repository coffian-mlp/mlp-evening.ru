# Тесты MLP-Evening

Два уровня, оба — чистый PHP без фреймворков (ADR-1: без Composer):

- **Юниты** `test_*.php` — чистая логика без БД (парсеры, политики, форматтеры).
- **Интеграционные** `integration_*.php` — реальная MySQL из Docker-контура
  через публичные методы менеджеров (общие помощники — `integration_helpers.php`).

## Быстрый старт (полный контур)

Конфигурация — `.env` в корне (нет в git): `cp .env.example .env`, для Docker-контура
дефолты из примера подходят как есть (host `db`, креды совпадают с docker-compose,
`CENTRIFUGO_API_KEY` пустой = realtime не ходит в сеть). Его же читает docker-compose.

```bash
docker compose up -d db php       # только БД и PHP: nginx/centrifugo не нужны
docker compose exec -e IT_DB_WAIT=90 php php tests/run_all.php
docker compose exec php php migrate.php --status # 43 applied, 0 pending для snapshot MLP-455
```

Первый запуск после `up -d`: MySQL может ещё инициализироваться — добавь
ожидание `docker compose exec -e IT_DB_WAIT=90 php php tests/run_all.php`.

## Раннер

`php tests/run_all.php` — гоняет все тесты, каждый отдельным процессом.

- `✓ OK` / `✗ FAIL` / `− SKIP` по каждому скрипту + итоговая сводка;
- exit code `0` — ни одного FAIL, `1` — есть упавшие (вывод упавших печатается);
- флаг `-v` — показать вывод и для SKIP.

**Протокол вердиктов:** OK требует `ALL PASS` в выводе (exit 0 сам по себе —
не успех: `die()` тоже выходит нулём); SKIP — первая строка `SKIP: <причина>`
и exit 0; всё остальное FAIL. Повисший тест убивается по таймауту
(`RUN_ALL_TIMEOUT`, дефолт 120 с) → FAIL.

**Guard боевой БД:** интеграционные тесты пишут в БД, поэтому запускаются
только когда `DB_HOST=db` и присутствует `/.dockerenv`. На Mac и любом
другом хосте (в т.ч. на проде, куда тесты попадают через git pull) — SKIP.
Осознанный обход: `IT_ALLOW_DB=1`.

Запуск на хосте (macOS) — тоже валиден: юниты пройдут, интеграционные
скипнутся. Полная проверка — только внутри php-контейнера (Linux,
чувствительная к регистру ФС — важно для автозагрузчика PSR-4).

## Автозагрузка (MLP-248/249)

Классы проекта грузятся через `autoload.php` — чистый PSR-4 от `src/`
(namespace = путь, имя файла = класс). `integration_helpers.php` подключает его
сам; юнит-тестам чистых классов достаточно `require_once __DIR__ . '/../autoload.php'`.
Конформность стережёт `test_autoload.php`: класс без namespace или мимо пути = FAIL.

## Интеграционные тесты: правила

- БД проверяется **до** `Database::getInstance()` (тот делает `die()`):
  `it_require_db()` из `integration_helpers.php` вернёт соединение или SKIP.
- Тестовые данные — только с маркерами `it_user_*` / `it_opt_*`; тест обязан
  удалить их за собой (последние check'и — проверка чистоты).
- Писать через публичные методы менеджеров (шов по architecture.md), прямой
  SQL — только фикстурная уборка или проверка состояния.
- Боевая БД не участвует никогда.

## Bootstrap и чистый прогон

Для нового Compose-тома PHP entrypoint вызывает `php bootstrap.php --if-empty --demo --wait=90`
до запуска PHP-FPM. База MySQL при этом изначально пуста. `IT_DB_WAIT=90` также ждёт
завершения bootstrap перед интеграционными тестами.

```bash
docker compose -p mlp-test up -d db php
docker compose -p mlp-test exec -e IT_DB_WAIT=90 php php tests/run_all.php
docker compose -p mlp-test exec php php migrate.php --status
docker compose -p mlp-test exec -e IT_FEEDBACK_SCRATCH=1 php php tests/integration_feedback_dedup.php
docker compose -p mlp-test down -v
```

Команда `down -v` удаляет только одноразовые данные выбранного тестового проекта.
Для существующей установки применяется обычный `php migrate.php`, который выполняет
только непринятые SQL-файлы по `schema_migrations`.

CLI для пустой MySQL 8.0 с конфигурацией из `.env` использует тот же entry point.

```bash
php bootstrap.php                 # схема и обязательные defaults
php migrate.php --status          # snapshot MLP-455 даёт 43 applied, 0 pending
```

Эквивалентная команда — `php migrate.php --bootstrap`. Флаг `--demo` при первом
запуске добавляет демонстрационный каталог и аккаунт admin с паролем password.
Основная установка создаёт только системного бота с отключённым паролем.
Повтор bootstrap сохраняет пользовательские данные и журнал, demo на повторе не добавляется.

`database.sample.sql` содержит структуру, `database.seed.sql` — обязательный seed,
`database.demo.sql` — необязательные dev fixtures. Импортировать отдельно sample
поверх существующей БД нельзя. `database/bootstrap-manifest.php` фиксирует SQL-хеши,
структурный контракт, seed и конкретные 43 исторических имени миграций. Новые файлы
применяются обычным upgrade и не включаются в baseline автоматически.

На fresh install до DDL записывается durable marker `schema_bootstrap`. Структура и
обязательный seed проверяются до атомарной регистрации manifest. При частичном DDL
bootstrap и migrate отказывают с диагностикой incomplete bootstrap. Сначала требуется
исследовать эту установку. Для одноразового тестового тома допустимо его пересоздание.
Восстановление существующей установки выполняется отдельно, автоматической очистки нет.

Для изменения snapshot нужен новый осознанный manifest с проверенными хешами и
регрессионными проверками fresh/upgrade. Опубликованные SQL-миграции остаются неизменными.
`integration_schema_bootstrap.php` проверяет оба режима sql_mode, повтор, drift и
частичный старт. `integration_schema_upgrade.php` проверяет текущую схему с 43 applied
и предыдущую схему с 41 applied, до миграций ролей и stream monitor от 27.10.2026.
Все строки этих проверок синтетические, БД создаются и удаляются внутри Docker.

## Playwright (UI, отдельно)

MLP-456: snapshot схемы обновлён добавляющим флагом `bot_memory.is_archived` и индексом активных записей. Исторический журнал snapshot по-прежнему содержит 43 миграции; `migrate.php` доводит установку до 45 файлов через semantic-memory и memory-archive. `integration_memory_archive.php` проверяет архивирование, изоляцию читателей, поиск, приватность и конкурентную дедупликацию; `integration_memory_archive_migration.php` — чистый/заполненный upgrade в обоих SQL modes. Браузерный набор `mlp-456-memory-archive.ui.spec.js` разрешён только для явного localhost и собственного Docker-контура.

`tests/playwright/` — браузерные сценарии, гоняются против прода
(см. README там). В раннер `run_all.php` не входят.

### MLP-359/360: изолированные проверки авторизации и реакций

Новые проверки используют настоящий PHP/MySQL и исходный браузерный код:

- `integration_ban_read_only.php` — пароль, социальная идентичность, remember-cookie, чтение и запрет отправки при действующей санкции.
- `integration_bot_reactions.php` — все четыре новые реакции при `ai_reactions=0/1`: реальные записи БД, очистка маркера из текста и цитата. Генератор ответа инжектируется через существующий тестовый seam; внешняя LLM не вызывается.
- `test_chat_reaction_catalog.php` — согласованность UI/backend/parser/prompt, сохранение прежних 12 ключей и glyphs.
- `mlp-359-ban-read-only.ui.spec.js` — вход, публичная история/live, уведомление, бан/мут и antiflood в Chromium/Firefox.
- `mlp-360-reactions.spec.js` / `mlp-360-reactions.ui.spec.js` — реальный API toggle и оба шаблона чата на 1280/360 px. Событие `reaction_update` доставляется контролируемым EventSource: проверяется renderer, а не работоспособность живого SSE/Centrifugo транспорта.

PHP-файлы автоматически входят в `run_all.php`. Fixtures для браузера запускаются только CLI с `DB_HOST=db`; MLP-360 дополнительно требует Docker. Браузерные сценарии ограничены localhost и не предназначены для запуска против прода. Пароли локальных фикстур не являются боевыми credentials. Сообщения создаются через ChatManager; очистка относится только к своим фикстурам.

Для воспроизводимого запуска нужен **отдельный тестовый checkout** без production `.env`/config и без ранее импортированных боевых данных. Создай `.env` из `.env.example` с `DB_HOST=db`, тестовыми DB credentials, `CHAT_DRIVER=sse` и пустым `CENTRIFUGO_API_KEY`. Compose-проект `mlp359` и путь override ниже закреплены в browser fixture MLP-359; файл создаётся явно, заранее существующий ignored-файл не требуется.

```bash
mkdir -p docs/tests/MLP-359 docs/private
cat > docs/tests/MLP-359/compose.override.yml <<'YAML'
services:
  php:
    ports:
      - "127.0.0.1:8091:8091"
YAML

mlp_test_compose() {
  docker compose -p mlp359 -f docker-compose.yml \
    -f docs/tests/MLP-359/compose.override.yml "$@"
}
mlp_test_compose up -d --build db php
mlp_test_compose exec -T -e IT_DB_WAIT=90 php php tests/run_all.php
```

Этот fresh-контур использует штатный bootstrap с актуальной схемой и seed. Исторические миграции поверх текущего sample не replayятся: старый ENUM в миграции команды рисования несовместим с текущим набором значений. Полный PHP suite и браузерные тесты выполняются **последовательно**: общий suite временно меняет настройки и создаёт/удаляет данные, поэтому одновременный запуск делает результаты недостоверными. Для MySQL 8 проверка AUTO_INCREMENT в `integration_live_confirm` отключает кеш статистики только для своей тестовой session; server-global настройки не меняются.

Перед браузерными проверками сохрани и отключи `ai_enabled` в этой тестовой БД, чтобы вход не запускал внешнюю LLM:

```bash
mlp_test_compose exec -T php php -r '
require "autoload.php";
if (Infra\Env::get("DB_HOST") !== "db") exit(1);
$p = "docs/private/mlp-browser-ai.json";
if (is_file($p)) exit(2);
$c = Infra\ConfigManager::getInstance();
umask(0077);
file_put_contents($p, json_encode(["before" => $c->getOption("ai_enabled", null)]));
$c->setOption("ai_enabled", "0"); $c->flushCache();'
```

В другом терминале определи ту же функцию `mlp_test_compose` и запусти отдельный сервер; восемь workers нужны, чтобы открытый SSE-запрос не блокировал login/API:

```bash
mlp_test_compose exec -T -e PHP_CLI_SERVER_WORKERS=8 php \
  php -S 0.0.0.0:8091 -t /var/www/html
```

В первом терминале установи локальный Playwright, если он отсутствует, и выполни проверки. Fixture MLP-359 создаётся и удаляется самим spec; fixture MLP-360 управляется отдельно:

```bash
npm install --no-save --package-lock=false @playwright/test
npx playwright install chromium firefox
MLP_BASE_URL=http://localhost:8091 npx playwright test \
  -c tests/playwright/playwright.config.js mlp-359-ban-read-only.ui.spec.js
mlp_test_compose exec -T php php tests/playwright/mlp-360-fixture.php setup
MLP_BASE_URL=http://localhost:8091 npx playwright test \
  -c tests/playwright/playwright.config.js mlp-360-reactions
mlp_test_compose exec -T php php tests/playwright/mlp-360-fixture.php cleanup
```

После проверки, в том числе при падении Playwright, выполни cleanup MLP-360 при наличии его fixture-файла и восстанови настройку. Cleanup проверяет точные ID/владельца/маркер; тестовая история не очищается массово. Временный файл credentials имеет права 600 и удаляется cleanup.

```bash
mlp_test_compose exec -T php php -r '
require "autoload.php";
if (Infra\Env::get("DB_HOST") !== "db") exit(1);
$p = "docs/private/mlp-browser-ai.json";
$saved = json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$c = Infra\ConfigManager::getInstance();
if ($saved["before"] === null) {
  $d = Infra\Database::getInstance()->getConnection();
  $s = $d->prepare("DELETE FROM site_options WHERE key_name = ?");
  $key = "ai_enabled"; $s->bind_param("s", $key); $s->execute();
} else { $c->setOption("ai_enabled", (string)$saved["before"]); }
$c->flushCache(); unlink($p);'
```

После восстановления настройки выполни `mlp_test_compose stop php`: остановится только PHP-контейнер выделенного тестового проекта, БД и том сохранятся. Ctrl+C у клиента `docker exec` сам по себе может оставить workers PHP CLI server. Production процессы не участвуют.


## MLP-361: плейлист, интерактивные команды и LLM-поиск

Подтверждённая приёмка: 93 PHP-скрипта, 20 Playwright-сценариев Chromium/Firefox и четыре визуальных случая (embedded/popup, 1280/360 px). Тесты используют реальную MySQL, HTTP, DOM и SSE; fake допускается только на внешних LLM/curl/Centrifugo границах. Browser Centrifugo service не подменяется доказательством SSE: publish payload проверяется отдельно через настоящий ChatManager.

Пожелания, completion, коррекции и тестовые сообщения изменяют БД: запускать только локально, `DB_HOST=db`. Browser fixture дополнительно проверяет CLI, Docker и localhost. Production интеграционные SKIP не являются PASS.

### Подготовка среды

Из корня репозитория, после локальной настройки `.env` по `.env.example`:

```bash
mkdir -p docs/tests/MLP-359
cat > docs/tests/MLP-359/compose.override.yml <<'YAML'
services:
  php:
    image: mlp-eveningru-php
    ports:
      - "127.0.0.1:8091:8091"
YAML
mlp_test_compose() {
  docker compose -p mlp359 -f docker-compose.yml \
    -f docs/tests/MLP-359/compose.override.yml "$@"
}
mlp_test_compose up -d --build db php
```

Override хранится локально вне Git; путь совпадает с CLI fixture/spec. Для новой БД Compose вызывает штатный bootstrap. Не импортировать sample поверх существующей БД. Три миграции MLP-361 можно применить выборочно командой ниже, не повторяя всю историческую цепочку. На старой БД сначала сверить схему/журнал и предварительно применённые версии; исторические ENUM-миграции могут сузить современные значения даже при пустом sql_mode.

```bash
mlp_test_compose exec -T php php <<'PHP'
<?php
require 'autoload.php';
require 'migrate.php';
if (Infra\Env::get('DB_HOST') !== 'db') exit(1);
$db = Infra\Database::getInstance()->getConnection();
migrate_ensure_table($db);
$applied = migrate_applied($db);
foreach (['2026_10_05_playlist_wishes.sql',
          '2026_10_05_command_interactions.sql',
          '2026_10_05_playlist_commands.sql'] as $name) {
    if (in_array($name, $applied, true)) continue;
    migrate_apply_file($db, 'migrations/' . $name);
    migrate_record($db, $name);
}
PHP
mlp_test_compose exec -T php php tests/run_all.php
```

Не использовать `--baseline` без проверки соответствия всей схемы: он помечает историю выполненной, но не добавляет отсутствующие изменения. `php migrate.php` без ограничения допустим только после проверки, что в pending действительно находятся нужные новые файлы.

### HTTP и браузеры

Создать игнорируемую `.env.local` с `LOCAL_URL=http://127.0.0.1:8091`. В отдельном терминале запустить принадлежащий тестовой среде сервер:

```bash
mlp_test_compose exec -T -e PHP_CLI_SERVER_WORKERS=24 php \
  php -S 0.0.0.0:8091 -t /var/www/html
```

24 workers нужны полному набору SSE-сценариев: восемь workers вместе с родительским обработчиком в диагностике были заняты девятью SSE-подключениями, блокируя login/read API. Это настройка локального тестового сервера, не требование изменить production PHP-FPM.

Установить Playwright/browser runtimes по разделу выше; затем последовательно:

```bash
mlp_test_compose exec -T php php tests/playwright/mlp-361-interactions-fixture.php setup
MLP_BASE_URL=http://127.0.0.1:8091 npx playwright test \
  -c tests/playwright/playwright.config.js \
  tests/playwright/mlp-361-command-interactions.ui.spec.js \
  --project=chromium-ui --project=firefox-ui
mlp_test_compose exec -T php php tests/playwright/mlp-361-interactions-fixture.php cleanup
```

Cleanup обязателен и при FAIL: восстанавливает AI/bot/queue/mode, удаляет только собственные изолированные данные и локальный mode-600 credentials-файл. Во время активного browser fixture не запускать PHP suite: оба изменяют глобальные опции БД. После cleanup остановить только тестовый PHP-контейнер (`mlp_test_compose stop php`); production процессы не затрагиваются. Ctrl+C клиента `docker exec` может оставить CLI workers.

### Диагностика и coverage

При widget «Загрузка выбора…» проверить HTTP read API и занятость SSE workers; повышение таймаутов не заменяет устранение исчерпания пула. Screenshot должен отражать реальный renderer, а не скопированную разметку. При случайном FAIL теста рисования проверить уникальность fixture автора и URL: одинаковая подпись/URL штатно подавляется защитой от duplicate сообщения; действующий regression fixture изолирован.

PCOV-приёмка MLP-361: 889/978=90.90% изменённых исполняемых PHP-строк относительно HEAD до коммита, объединённые PHP suite и browser HTTP. Это line coverage изменения, не всего проекта, не ветвей и не JS/CSS. Инструментирование остаётся локальным; не включать его или credentials в релиз.


## MLP-362: регрессии поиска и живых подтверждений

Используется локальный контур и последовательный запуск из раздела MLP-361 выше; новых миграций и настроек нет. Расширены существующие скрипты, поэтому общее число PHP-скриптов осталось 93. Итоговая независимая приёмка: 93 PHP-скрипта PASS без SKIP/FAIL и 22 Playwright-сценария PASS в Chromium/Firefox. Дополнительный браузерный сценарий проверяет реальную команду первой серии: предложение без записи голоса, безопасный ответ, нажатие владельцем и восстановление результата после reload.

Для целевой диагностики после подготовки среды:

```bash
mlp_test_compose exec -T php php tests/test_episode_catalog.php
mlp_test_compose exec -T php php tests/test_episode_resolver.php
mlp_test_compose exec -T php php tests/test_playlist_commands.php
mlp_test_compose exec -T php php tests/integration_playlist_commands.php
mlp_test_compose exec -T php php tests/integration_playlist_llm_scoped.php
```

Полная приёмка — `tests/run_all.php` и прежний `mlp-361-command-interactions.ui.spec.js` с setup/cleanup по разделу выше. Не запускать PHP suite одновременно с активным браузерным fixture: они меняют глобальные опции одной тестовой БД. Проверки фактического producer/worker и LLMManager подменяют только внешний транспорт; helper-подкласс не заменяет доказательство их взаимодействия.

Покрыты тематический query normalizer, условная идентичность адресата, исходное описание в verifier, строгие citations/code/title, нулевое число LLM-вызовов для первой серии/фильма, диапазоны канонических двусерийников и отдельное пожелание выбранной части. Негативные случаи включают невалидный normalizer, несвязанные части, чужие источники, служебное эхо, ложное собственное действие бота и предложение кнопок без кандидатов. Общий бюджет 55 секунд и отсутствие HTTP при остатке менее пяти секунд проверяются отдельно.

Если описание не найдено, проверить фактические источники поиска и отказ независимой проверки; добавление произвольного ID или ослабление citation guard недопустимо. Такой исход требует честного уточнения без кнопок, а не сообщения о поломке интерфейса. Ограниченный read-only smoke реальных провайдеров охватывает пять исходных запросов; отдельные успешные ответы не гарантируют точность произвольного описания.

PCOV для MLP-362: 111/111 изменённых исполняемых PHP-строк покрыты относительно коммита до исправления. Это покрытие строк изменения, а не всего проекта, ветвей, JavaScript или CSS. Полные логи, предыдущие неудачные попытки и итоговая приёмка хранятся локально в `docs/tests/MLP-362.tests.md`; рабочие материалы и инструментирование в релиз не включаются.


## MLP-364: уточнение и отмена выбора

Независимая приёмка: 95 PHP-скриптов PASS без SKIP/FAIL, 34 новых и 24 регрессионных Playwright-сценария PASS в Chromium/Firefox. Покрытие изменённых исполняемых PHP-строк: 788/862 = 91.42%; это не покрытие ветвей или JavaScript. Исторические числа разделов выше относятся к их тикетам.

Использовать изолированный Docker-контур, функцию `mlp_test_compose` и HTTP-сервер с 24 workers из раздела MLP-361. Для обновляемой тестовой схемы отдельно применить и зарегистрировать `migrations/2026_10_06_command_interaction_context.sql` тем же ограниченным способом; свежий sample уже содержит nullable `context_json`. Не переигрывать всю историю миграций поверх sample. PHP suite и браузерные fixtures выполняются последовательно:

```bash
mlp_test_compose exec -T php php tests/run_all.php
mlp_test_compose exec -T php php tests/playwright/mlp-361-interactions-fixture.php setup
MLP_BASE_URL=http://127.0.0.1:8091 npx playwright test \
  -c tests/playwright/playwright.config.js mlp-364-command-refinement.ui.spec.js
mlp_test_compose exec -T php php tests/playwright/mlp-361-interactions-fixture.php cleanup
mlp_test_compose exec -T php php tests/playwright/mlp-361-interactions-fixture.php setup
MLP_BASE_URL=http://127.0.0.1:8091 npx playwright test \
  -c tests/playwright/playwright.config.js mlp-361-command-interactions.ui.spec.js
mlp_test_compose exec -T php php tests/playwright/mlp-361-interactions-fixture.php cleanup
```

Cleanup обязателен после FAIL и перед следующей fixture. После проверки завершить только принадлежащий тестовому контуру HTTP-сервер: Ctrl+C клиента `docker exec` может оставить PHP workers. Проверить отсутствие fixture credentials, восстановление настроек и завершение точных процессов своего CLI-сервера; остальные процессы не затрагивать.

Проверки используют реальные source/quotes, HTTP actions, очередь, worker и renderer; подменяется внешний provider transport. Покрыты цитированное и однозначное адресное уточнение, contextual exact ID, неизменный TTL, пределы 600/300/8, источник после edit/delete, гонки accept/refine/cancel, enqueue crash, lease/replay и сохранённый verified result. Terminal recovery доставляет сохранённый outcome без повторного эффекта; устаревший источник подавляет публикацию. PHP-диагностика доступна через `test_command_interaction_continuation.php` и `integration_command_interaction_continuation.php`; итоговые команды, логи и ограничения доказательств — в локальном `docs/tests/MLP-364.tests.md`. Проверки регрессии дополнительно охватывают HTML-символы и литеральные entities во всех привязках, согласованность SQL/ISO UTC времени, совместимость прежних форматов и immutable input replay. Actual HTTP/worker сценарий проверяет доступность после reload и обычное цитированное описание без `Уточнение:`; проверки поисковых и live-реплик сохраняют ограничения исходного запроса и адресность автора. Результаты исправления — в локальном `docs/tests/MLP-364-regression.tests.md`.

### MLP-364 follow-up: action background and first appearance

Focused PHP assertions in `integration_playlist_llm_scoped.php`, `test_episode_resolver.php`, `test_playlist_commands.php` and `integration_command_interactions.php` cover shared ordinary-context compatibility; owner/global memory without unrelated history or foreign online dossier; bounded pin/presence; exact server-recipient allowlist; all formatting paths, private optional terminal context, verified evidence and truthful phase wording. Finite S01E01 aliases retain independent identity/first-appearance verification and negative code/title/citation cases.

The existing `mlp-364-command-refinement.ui.spec.js` additionally exercises actual HTTP→plain quoted first-appearance refinement→queue/worker→verified alternate title→canonical button→one wish after click. Only external provider transport is replaced. Use the existing MLP-364 commands above with a dedicated 24-worker PHP HTTP server for concurrent SSE requests. Fixture setup temporarily pins queue delays to zero and cleanup restores settings and removes only owned fixture data; shared Docker remains running.

Implementation final result: 95 PHP and 40 affected + 24 legacy Playwright PASS. Independent QA: 95 PHP PASS, 39 affected cases PASS plus unchanged exact-case repeat PASS after an afterEach screenshot timeout, 24 legacy PASS. The capture diagnostic remains recorded; no product assertion or timeout was changed. Same-source PCOV changed executable PHP lines 88/91 = 96.70%, not branch/JS coverage. Local reports: `docs/tests/MLP-364-followup.tests.md` and `docs/qa/MLP-364-followup.qa.md`; production read-only/provider probes do not substitute for Docker full-flow checks.

## MLP-365: рейтинговые пожелания

Этот раздел описывает прежний WEB-механизм и исторические результаты. Новые рейтинговые запросы с MLP-366 используют локальный SQL; сохранённые v1-доказательства остаются совместимыми. Текущий порядок проверок приведён ниже.

Используется тот же изолированный Compose-проект `mlp359` с `DB_HOST=db`, HTTP-сервером на 24 workers и последовательным выполнением PHP/browser suites. Новые `test_rating_search_proof.php` и `test_rating_episode_search.php` входят в `run_all.php`: проверяют независимость двух WEB-поисков, сравнение средних оценок, вычисление рангов/равенства, распределения низких и высоких голосов, цитированные источники, ограничения и размеры доказательств. Дополненные интеграционные тесты проверяют частный сохранённый результат, повтор без нового поиска, полный envelope/fallback, metadata дочернего выбора и адресность ответа.

```bash
mlp_test_compose exec -T php php tests/run_all.php
mlp_test_compose exec -T php php tests/playwright/mlp-361-interactions-fixture.php setup
MLP_BASE_URL=http://127.0.0.1:8091 npx playwright test \
  -c tests/playwright/playwright.config.js \
  tests/playwright/mlp-365-rating-search.ui.spec.js \
  --project=chromium-ui --project=firefox-ui
mlp_test_compose exec -T php php tests/playwright/mlp-361-interactions-fixture.php cleanup
```

После cleanup новые и прежние browser suites запускаются отдельно с новым fixture setup: `mlp-364-command-refinement.ui.spec.js` и `mlp-361-command-interactions.ui.spec.js` дают 64 прежних сценария в двух движках. Общий PHP suite не запускать при активной browser fixture. Cleanup восстанавливает восемь сохранённых настроек, включая heartbeat, и удаляет только собственные данные/credentials. Завершить только свой HTTP-сервер; shared Docker сохраняется.

Восемь новых actual HTTP→queue→worker сценариев покрывают best→обычную цитату worst с сохранением персонажа, повторное уточнение дочернего выбора без нового WEB до ввода, неполное сравнение, неподдерживаемый источник, обязательное распределение для спорного варианта, неизменный TTL и один эффект после кнопки. Helper проверяет preview и фактический ID цитаты в POST. Подменяется только внешний provider transport; источники и числовые assertions не ослабляются.

Независимый QA: 97/97 PHP PASS (SKIP0/FAIL0, 11,0 с), новые 8/8 Playwright PASS (40,1 с), прежние 64/64 PASS (4,1 мин). Diagnostic 7/8 с отсутствующей цитатой в Firefox сохранён; после исправления helper полный новый набор прошёл. Покрытие изменённых исполняемых PHP-строк PCOV: 401/405 = 99,01%, проверено на том же runtime; это не branch/JS coverage и не точность внешней модели. Локальные отчёты: `docs/tests/MLP-365-rating-search.tests.md`, `docs/qa/MLP-365-rating-search.qa.md`.

Native provider-проверки подтвердили шесть вариантов нормализации, но snippets не доказали сравнительного лидера best/worst. Такие ответы должны оставаться уточнением; deterministic fixtures не считаются доказательством успешного live-рейтинга. Остаточный риск модельного извлечения и доступность данных источника сохраняются.

## MLP-366: локальные рейтинги эпизодов

Используется существующий изолированный Compose-проект `mlp359`, `DB_HOST=db`, HTTP на localhost:8091 с 24 workers. При уже созданной тестовой БД применить и зарегистрировать только новую `2026_10_07_episode_ratings.sql`; свежий `database.sample.sql` содержит расширение. Не переигрывать всю историю миграций поверх sample. PHP suite и браузерные fixtures выполняются последовательно; интеграционные проверки записи запрещены при `db.host !== db`.

```bash
mlp_test_compose exec -T php php tests/run_all.php
mlp_test_compose exec -T php php tests/playwright/mlp-361-interactions-fixture.php setup
MLP_BASE_URL=http://127.0.0.1:8091 npx playwright test \
  -c tests/playwright/playwright.config.js \
  tests/playwright/mlp-366-local-episode-ratings.ui.spec.js \
  --project=chromium-ui --project=firefox-ui
mlp_test_compose exec -T php php tests/playwright/mlp-361-interactions-fixture.php cleanup
```

Новый специальный `mlp-366-ratings-fixture.php` использует общего помощника, сохраняет исходные rating-колонки/header и загружает изолированный снимок через настоящий owner. Cleanup обязателен после каждого набора, включая ошибочный прогон. Затем отдельными setup/run/cleanup выполнить текущий `mlp-365-rating-search.ui.spec.js`, `mlp-364-command-refinement.ui.spec.js` и `mlp-361-command-interactions.ui.spec.js`; четыре набора дают 80 сценариев в Chromium/Firefox. Останавливается только свой HTTP-сервер; общий Docker сохраняется.

`test_episode_rating_snapshot.php` проверяет строгую независимую идентичность, опубликованную оценку отдельно от histogram mean, десять bins/число голосов, population SD, даты и границы. `integration_episode_ratings.php` проверяет dry-run без записи, CLI, CAS, атомарный rollback/повтор/восстановление архивного снимка, полноту метрик, ранжирование до display cap, равенства и все критерии. Двухпроцессные проверки воспроизводят импорт во время чтения при READ-COMMITTED; собственный RR-снимок и передача transient SQL error защищают от смешения наблюдений. Старый код чтения и чистого генератора проверен на расширенной схеме без удаления колонок.

Адаптация MLP-365 ограничена rating-specific transport/fixture: fresh v2 нормализуется в локальный поиск; новый v1 ответ отвергается. Сохранённые v1 snapshots проверяются отдельно. Сюжетный поиск и независимая проверка принадлежности остаются внешними, рейтинговый WEB не вызывается. Actual HTTP→queue→worker→SQL→widget проверяет best/worst, SD и отдельный love/hate, цитированные уточнения, отмену, владельца, source edit, устаревшие данные и один эффект после кнопки. Подменяется только внешний provider transport.

Независимый QA: 99/99 PHP PASS, без SKIP; 80/80 Playwright PASS. После уточнения инструкции для стандартного отклонения повторно прошли 99/99 PHP и 8/8 затронутых Playwright-сценариев; прежние 80/80 сохранены как проверка остальных неизменённых сценариев. Текущее покрытие изменённых исполняемых PHP-строк PCOV — 403/420 = 95,95% относительно исходной версии до MLP-366; это не branch/JS coverage и не доказательство точности внешней модели. Реальный источник подтверждается отдельно: 224 записей, полные распределения и same-observation N, строгий валидатор и production dry-run. Исходные данные не являются синтетическим UI fixture. Локальные отчёты: `docs/tests/MLP-366-local-episode-ratings.tests.md`, `docs/qa/MLP-366-local-episode-ratings.qa.md`.


## MLP-367: публичный каталог и общий административный список

Изолированный Compose-проект и последовательность PHP/HTTP/fixtures сохраняются. Новый fixture `mlp-367-catalogue-fixture.php` допускается только при `db.host=db`: сохраняет настройки, рейтинги и меню, создаёт собственных пользователей и эпизоды, а затем восстанавливает исходное состояние. Setup, тесты и cleanup выполняются последовательно, cleanup обязателен также при ошибке. HTTP-сервер с 24 workers запускается отдельно; останавливаются только собственные процессы, общий Docker сохраняется.

```bash
mlp_test_compose exec -T php php tests/run_all.php
mlp_test_compose exec -T php php tests/playwright/mlp-367-catalogue-fixture.php setup
MLP_BASE_URL=http://127.0.0.1:8091 npx playwright test \
  -c tests/playwright/playwright.config.js \
  tests/playwright/mlp-367-episode-catalogue.ui.spec.js \
  --project=chromium-ui --project=firefox-ui
mlp_test_compose exec -T php php tests/playwright/mlp-367-catalogue-fixture.php cleanup
```

Новые PHP-проверки: `integration_episode_catalogue.php`, `integration_episode_catalogue_menu.php`, `test_episode_catalogue_api.php`. Они проверяют согласованный RR-снимок проекции при конкурентном обновлении, DTO и неизвестные/устаревшие значения, гистограммы и парные эпизоды, общий wish/cancel и привязку повторов, сохранение пользовательского меню. Реальные двухпроцессные сценарии проверяют оставшуюся одну дневную квоту, replay, cooldown и ban/mute/deleted после ожидания блокировки, включая внешнюю RR-транзакцию с уже созданным снимком.

Новый Playwright-набор: 8 сценариев в двух движках, всего 16. Проверяются guest/public/admin, поиск и числовая сортировка, histogram/details, authenticated wish/cancel, CSRF и серверный actor, санкции, пропавший эпизод, квота, потерянный ответ и повтор того же UUID. Только сценарий потерянного ответа блокирует service worker для наблюдаемого перехвата сети; остальные используют обычную загрузку. Сохранённые наборы 361/364/365/366 выполняются отдельно с их setup/cleanup и дают ещё 80 проверок интерактивных команд, уточнений и рейтингового поиска.

Независимый QA: **102/102 PHP PASS, SKIP0/FAIL0; 96/96 Playwright PASS = 16 новых + 80 сохранённых**, Chromium/Firefox. Просмотрены реальные desktop/360px изображения публичного и административного компонента. Настройки, включая timestamps, рейтинги и меню восстановлены точно; собственные fixtures и credentials удалены, только собственный HTTP остановлен. Покрытие изменённых исполняемых PHP-строк PCOV относительно `5e7921a`: **160/166 = 96,39%**, включая страницу и общий компонент. Это line coverage PHP, не branch/JS coverage и не проверка точности внешней модели. Отчёты хранятся локально в `docs/tests/MLP-367-episode-catalogue.tests.md` и `docs/qa/MLP-367-episode-catalogue.qa.md`.


## MLP-368: уведомления каталога и текущая квота

`test_episode_wish_calendar.php` проверяет полуоткрытые границы суток Europe/Kaliningrad, полночь и исторические дни длительностью23/25 часов. Расширенный `integration_episode_catalogue.php` проверяет текущую квоту в одном RR-снимке с каталогом, отсутствие записи/блокировки при чтении, distinct принятых эпизодов, историю отменённых/исполненных пожеланий и свежую квоту после повторов из текущего и предыдущего дня. Квота не подменяется сохранёнными фактами прежнего ответа.

После полного PHP-прогона последовательно выполняются текущие каталожные наборы и затем отдельный прежний fixture361. Каталог содержит16 прежних и14 новых Playwright-проверок в Chromium/Firefox; набор361 содержит24 проверки. Перед всеми browser fixtures необходимо сохранить полный закрытый baseline настроек, включая timestamps, рейтингов, меню и счётчиков, удерживать его до завершения последнего cleanup и подтвердить точное восстановление. Восстановление timestamps допускается только после проверки неизменности значений, с условием CAS. Credentials и закрытый baseline удаляются; общий Docker остаётся запущенным.

```bash
MLP_BASE_URL=http://127.0.0.1:8091 npx playwright test \
  -c tests/playwright/playwright.config.js \
  tests/playwright/mlp-367-episode-catalogue.ui.spec.js \
  tests/playwright/mlp-368-catalogue-feedback.ui.spec.js \
  --project=chromium-ui --project=firefox-ui
```

Новые сценарии проверяют реальную ошибку из середины списка, видимое верхнее уведомление на360px и буквальное отображение HTML, SSR при нулевой квоте, отмену, потерянный третий ответ и повтор UUID, неверную/отсутствующую квоту, задержанный ответ и окончание суток без выдуманного остатка. Контролируемые неверные SSR-типы проходят фактический PHP-шаблон и browser unknown flow без записи повреждённого DTO в БД. Только новый набор блокирует service worker для воспроизводимого перехвата/часов; прежние обычные загрузки сохраняются.


Независимый QA MLP-368: **103/103 PHP PASS, SKIP0/FAIL0; 54/54 Playwright PASS =30 каталожных +24 прежних команд**, Chromium/Firefox. Текущее независимое покрытие изменённых исполняемых PHP-строк относительно `94fbc3e`: **33/33 =100%**; это line coverage PHP, не branch/JS coverage. Старые56 семантических сценариев MLP-364/365/366 остаются историческими доказательствами неизменённых путей и не включаются в текущие54. Локальные отчёты: `docs/tests/MLP-368-catalogue-feedback.tests.md`, `docs/qa/MLP-368-catalogue-feedback.qa.md`.


## MLP-375: Telegram announcement editor

New PHP suites cover bounded previous-event evidence, Moscow scheduling, captions and Roman numbering, owner-only Telegram updates, durable approval revisions, regeneration, delivery ambiguity and a fake-transport worker flow. Run with `php tests/run_all.php` inside an isolated Docker database (`DB_HOST=db`). External Telegram/LLM requests are replaced by test seams. Integration fixtures remove only their own rows and restore options. The generation/content tests do not publish messages.

Production activation also requires a dedicated bot token, independently verified owner Telegram ID, channel posting permission and a private `/start`. See `deploy/telegram-announcements.md`. Telegram mobile UI acceptance is performed in the owner's actual conversation after configuration; it cannot be verified through the site's Playwright fixtures.

### MLP-456 — локальные UI-проверки инвалидизации памяти

`mlp-456-semantic-memory.ui.spec.js` проверяет edit/delete/restore чата, правку памяти, отзыв согласия и откат источника при отказе записи индекса. Fixture CLI разрешён только внутри Docker с `DB_NAME=mlp456pw` и `MLP456_PW_FIXTURE=1`. Временный trigger с SIGNAL моделирует отказ существующей производной таблицы, создаётся root только в этой disposable БД и удаляется cleanup. Внешняя LLM выключена. Сценарий не предназначен для production.

В корне рабочего checkout создай отдельную копию без production-конфигураций. Путь и порт закреплены в spec.

```bash
mkdir -p docs/tests/MLP-456/fix4/pw-workspace
rsync -a --exclude='.git' --exclude='docs' --exclude='dev_knowledge' \
  --exclude='node_modules' --exclude='.env*' --exclude='config.php' \
  --exclude='centrifugo_config.json' --exclude='upload' --exclude='logs' \
  --exclude='cache' --exclude='test-results' --exclude='playwright-report' \
  --exclude='.claude' --exclude='.codex' --exclude='.agents' --exclude='.cursor' \
  ./ docs/tests/MLP-456/fix4/pw-workspace/
python3 - <<'PY'
from pathlib import Path
import re, secrets
root = Path('docs/tests/MLP-456/fix4/pw-workspace')
text = Path('.env.example').read_text()
values = dict(DB_HOST='db', DB_NAME='mlp456pw', DB_USER='mlp456pw',
              DB_PASS=secrets.token_hex(16), DB_ROOT_PASS=secrets.token_hex(16),
              CHAT_DRIVER='sse', CENTRIFUGO_API_KEY='', CENTRIFUGO_SECRET='')
for key, value in values.items():
    pattern = r'(?m)^' + key + r'=.*$'
    text = re.sub(pattern, key+'='+value, text) if re.search(pattern, text) else text+'\n'+key+'='+value+'\n'
(root/'.env').write_text(text)
(root/'.env').chmod(0o600)
(root/'docs/tests/MLP-456').mkdir(parents=True, exist_ok=True)
PY
cat > docs/tests/MLP-456/fix4/pw.override.yml <<'YAML'
services:
  php:
    ports:
      - "127.0.0.1:18546:18546"
YAML
mlp456_pw_compose() {
  docker compose -p mlp456pw \
    -f docs/tests/MLP-456/fix4/pw-workspace/docker-compose.yml \
    -f docs/tests/MLP-456/fix4/pw.override.yml "$@"
}
mlp456_pw_compose up -d --build db php
mlp456_pw_compose exec -T php php migrate.php
```

В отдельном терминале определи ту же функцию и запусти сервер.

```bash
mlp456_pw_compose exec -T -e PHP_CLI_SERVER_WORKERS=8 php \
  php -S 0.0.0.0:18546 -t /var/www/html
```

Установленные Chromium и Firefox используются через штатный конфиг. При отсутствии пакета или браузеров выполни установку из раздела выше.

```bash
MLP_BASE_URL=http://localhost:18546 npx playwright test \
  -c tests/playwright/playwright.config.js mlp-456-semantic-memory.ui.spec.js \
  --project=chromium-ui --project=firefox-ui \
  --output=docs/tests/MLP-456/fix4/pw-results
mlp456_pw_compose down -v
```

После остановки контура удали только созданную `docs/tests/MLP-456/fix4/pw-workspace`, включая её тестовую `.env`. Fixture setup/cleanup вызывается spec автоматически. Если процесс теста оборван, перед повтором выполни его cleanup в работающем контуре через CLI с `MLP456_PW_FIXTURE=1`. PHP full suite и браузерные сценарии не запускаются одновременно в одной БД.


### MLP-456 — поиск в обычном ответе

`test_memory_retrieval.php` проверяет cosine/fusion, бюджет и echo guard; `integration_memory_recall.php` — fake-provider корпус, точные ники/даты, актуальность, приватность, оба ordinary входа, дедлайн API и реальную SQL-блокировку. Внешние провайдеры в тестах не вызываются. Оба файла входят в `tests/run_all.php`.

`mlp-456-recall-settings.ui.spec.js` запускается только локально. Повтори disposable-процедуру MLP-456 выше, заменив все пути `fix4/pw-workspace` и `fix4/pw.override.yml` на `phase2/pw-workspace` и `phase2/pw.override.yml`. Сохрани проект `mlp456pw`, новую синтетическую `.env` с DB_NAME=mlp456pw и порт 18546. После миграции и запуска PHP server:

```bash
MLP_BASE_URL=http://localhost:18546 npx playwright test \
  -c tests/playwright/playwright.config.js mlp-456-recall-settings.ui.spec.js \
  --project=chromium-ui --project=firefox-ui \
  --output=docs/tests/MLP-456/phase2/pw-results
```

Spec использует защищённую CLI fixture, не требует production credentials и проверяет эффективные значения, HTML max и серверное ограничение сохранения. После проверки выполни адресный compose down -v и удали только созданную phase2/pw-workspace с её тестовой `.env`. PHP suite и UI не должны одновременно использовать одну БД.


Починка MLP-456 phase2: `integration_memory_recall_review.php` через настоящий ordinary generation проверяет main/fallback/transient/language retry, truncation/error, централизованное redaction и атрибуцию одинаковых nickname в конечных фоновых/focus блоках. Векторы и ответы — synthetic fakes, внешнего API нет. Также проверяются пустой запрос, weak/partial/complete lexical evidence и структурный identifier. Набор входит в штатный runner; production guard общий с остальными integration.

`test_memory_recall_edges.php` проверяет JSON redaction при Unicode/slash escaping, вложенных массивах и сериализованных строках, побайтное сохранение обычного debug и адресную очистку имени бота без удаления темы. `integration_memory_recall_review.php` дополнительно проверяет эти сериализации в настоящем logger и сравнивает BOOLEAN evidence после очистки операторного ввода на MySQL. Оба файла входят в штатный runner; внешние API не вызываются.

`test_russian_stemmer.php` сверяет Snowball Russian с 256 официальными парами из `fixtures/russian_snowball.json`, словоформами проекта и отрицательными парами. `test_memory_recall_selection.php` и `integration_memory_recall_selection.php` проверяют явную цитированную тему, точное подтверждение SQL-кандидатов по основам и ранжирование по редкости темы. Эти наборы входят в штатный runner и не вызывают внешние API.
