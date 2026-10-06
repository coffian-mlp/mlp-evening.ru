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
docker compose exec php php migrate.php --status # проверить журнал; не переигрывать старый SQL на fresh sample
docker compose exec php php tests/run_all.php  # все тесты
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
только когда `DB_HOST=db` (Docker-контур). На любом
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

## Чистый прогон (эталон «как на новом клоне»)

```bash
docker compose down -v            # сброс тома БД (том пересоздастся из database.sample.sql)
docker compose up -d db php
docker compose exec -e IT_DB_WAIT=90 php php tests/run_all.php
docker compose exec php php migrate.php --status   # ожидающих быть не должно после migrate.php
```

Схема тома создаётся из `database.sample.sql` **только при первом старте
тома** — обновил сэмпл → нужен `down -v`.

## Playwright (UI, отдельно)

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

Этот fresh-контур использует актуальный `database.sample.sql`, покрывающий проверенные 81 PHP-тест. Исторические миграции поверх текущего sample не replayятся: старый ENUM в миграции команды рисования несовместим с текущим набором значений. Полный PHP suite и браузерные тесты выполняются **последовательно**: общий suite временно меняет настройки и создаёт/удаляет данные, поэтому одновременный запуск делает результаты недостоверными. Для MySQL 8 проверка AUTO_INCREMENT в `integration_live_confirm` отключает кеш статистики только для своей тестовой session; server-global настройки не меняются.

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

Override хранится локально вне Git; путь совпадает с CLI fixture/spec. Для новой БД Compose импортирует актуальный `database.sample.sql`. Не импортировать sample поверх существующей БД. Три миграции MLP-361 можно применить выборочно командой ниже, не повторяя всю историческую цепочку. На старой БД сначала сверить схему/журнал и предварительно применённые версии; исторические ENUM-миграции могут сузить современные значения даже при пустом sql_mode.

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
