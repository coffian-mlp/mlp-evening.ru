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
docker compose exec php php migrate.php        # догнать миграции (идемпотентно)
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
