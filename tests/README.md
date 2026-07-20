# Тесты MLP-Evening

Два уровня, оба — чистый PHP без фреймворков (ADR-1: без Composer):

- **Юниты** `test_*.php` — чистая логика без БД (парсеры, политики, форматтеры).
- **Интеграционные** `integration_*.php` — реальная MySQL из Docker-контура
  через публичные методы менеджеров (общие помощники — `integration_helpers.php`).

## Быстрый старт (полный контур)

`config.php` (нет в git) для Docker-контура — ровно такой (креды из `docker-compose.yml`;
голый `cp config.sample.php` даст нерабочие креды и все интеграционные тесты уйдут в SKIP):

```php
<?php
return [
    'db' => [
        'host' => 'db',              // сервис из docker-compose.yml — по нему же
        'name' => 'coffian_eplist',  // guard отличает тестовый контур от прода
        'user' => 'coffian_eplist',
        'pass' => 'HmyV4b2z',
        'charset' => 'utf8mb4'
    ],
    'chat' => [
        'driver' => 'sse',
        'centrifugo_api_url' => 'http://127.0.0.1:8000/api',
        'centrifugo_api_key' => '',  // пусто = broadcast не ходит в сеть
        'centrifugo_secret'  => '',
    ]
];
```

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
только когда `config.php` указывает на host `db` (Docker-контур). На любом
другом хосте (в т.ч. на проде, куда тесты попадают через git pull) — SKIP.
Осознанный обход: `IT_ALLOW_DB=1`.

Запуск на хосте (macOS) — тоже валиден: юниты пройдут, интеграционные
скипнутся. Полная проверка — только внутри php-контейнера (Linux,
чувствительная к регистру ФС — важно для автозагрузчика PSR-4).

## Автозагрузка (MLP-248)

Классы проекта грузятся через `autoload.php` (PSR-4 от `src/` + `src/classmap.php`
для глобальных). `integration_helpers.php` подключает его сам; юнит-тестам чистых
классов достаточно `require_once __DIR__ . '/../autoload.php'`. Новый глобальный
класс обязан попасть в classmap — иначе упадёт `test_autoload.php` (скан-гард).

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
