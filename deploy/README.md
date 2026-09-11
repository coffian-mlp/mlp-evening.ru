# deploy/ — развёртывание mlp-evening.ru на сервере

Инфраструктура как код (MLP-327): всё нестандартное, что нужно серверу помимо кода из git. Файлы — рабочие копии с боевого сервера (Timeweb Cloud, Ubuntu 24.04 + FASTPANEL, переезд 2026-09-10/11). Панель управляет nginx-vhost, php-fpm, MySQL и Let's Encrypt; всё остальное — отсюда.

## Состав

| Файл | Куда | Назначение |
|---|---|---|
| `nginx/mlp-evening.conf` | `/etc/nginx/fastpanel2-includes/mlp-evening.conf` | Правила приложения: прокси Centrifugo `/connection/`, SSE-фоллбек, запреты на непубличные каталоги и файлы (замена `.htaccess`), лимит тела запроса |
| `centrifugo/centrifugo.service` | `/etc/systemd/system/centrifugo.service` | systemd-unit Centrifugo v5 (отдельный пользователь, 127.0.0.1:8000) |
| `centrifugo/config.sample.json` | `/etc/centrifugo/config.json` | Образец боевого конфига; секреты подставить свои. Те же значения — в `.env` сайта (`CENTRIFUGO_API_URL`, `CENTRIFUGO_API_KEY`, `CENTRIFUGO_SECRET`) |
| `php/mlp-evening.ini` | настройки сайта в FASTPANEL или `/etc/php/8.3/fpm/conf.d/99-mlp-evening.ini` | Требуемые значения PHP и список расширений |
| `mysql/99-mlp-evening.cnf` | `/etc/mysql/mysql.conf.d/99-mlp-evening.cnf` | `sql_mode` пустой, часовой пояс UTC, кодировка/коллация, bind на localhost |
| `cron/worker.crontab` | `crontab -u fastuser` | Воркер бота раз в минуту |

Установка Centrifugo подробно — `../DEPLOY_CENTRIFUGO.md`. Локальный dev-контур — `../docker-compose.yml` (MySQL 8.0, тот же sql_mode-подход, см. комментарии в файле).

## Требования к серверу

- ОС: Ubuntu 24.04 (или Debian 12), системный часовой пояс **UTC**.
- nginx + PHP 8.3 (php-fpm) с расширениями `mysqli mbstring curl gd openssl opcache`; GD с поддержкой WebP.
- **MySQL 8.0** (не MariaDB): боевая база использует коллацию `utf8mb4_0900_ai_ci`.
- Centrifugo v5.4.x.
- Исходящий SMTP 465/587 для писем сброса пароля (у Timeweb Cloud закрыт по умолчанию — открывается запросом в поддержку).
- Ресурсы: 2 vCPU, 2 ГБ RAM минимум (4 ГБ комфортно), 20 ГБ диска.

## Порядок развёртывания (FASTPANEL)

1. **Сайт в панели:** домен `mlp-evening.ru` + `www`, обработчик PHP-FPM 8.3, режим nginx + php-fpm. Число воркеров php-fpm в UI отсутствует — значение хранится в БД панели (`website_backends.parameters.workers_count`, шаблон `/usr/local/fastpanel2/templates/virtualhost/configuration/fpm.conf.tpl`); рабочее значение — 6.
2. **PHP:** значения из `php/mlp-evening.ini` в настройках сайта. Проверка: `php-fpm8.3 -tt` или `phpinfo()` во временном файле.
3. **MySQL:** создать БД и пользователя в панели, положить `mysql/99-mlp-evening.cnf`, `systemctl restart mysql`. Проверка: `SELECT @@sql_mode, @@global.time_zone, @@collation_server`.
4. **Код:** `git clone git@github.com:coffian-mlp/mlp-evening.ru.git` в корень сайта (deploy-ключ в `/root/.ssh`, `git config --global --add safe.directory <путь>`). Владелец файлов — пользователь сайта. Создать `.env` (ключи: `DB_HOST DB_NAME DB_USER DB_PASS DB_CHARSET CHAT_DRIVER CENTRIFUGO_API_URL CENTRIFUGO_API_KEY CENTRIFUGO_SECRET`), каталоги `cache/ logs/ upload/` с правом записи. `php migrate.php`.
5. **Centrifugo:** бинарник в `/usr/local/bin`, пользователь `centrifugo`, `config.json` по образцу, unit, `systemctl enable --now centrifugo`. Проверка: `ss -tlnp | grep 8000` — только `127.0.0.1`.
6. **nginx:** `nginx/mlp-evening.conf` в `/etc/nginx/fastpanel2-includes/`, `nginx -t && systemctl reload nginx`.
7. **Cron:** строка из `cron/worker.crontab` под пользователем сайта. Через минуту `site_options.bot_worker_heartbeat` должен обновиться.
8. **TLS:** Let's Encrypt через панель (после того как DNS указывает на сервер).
9. **Firewall:** снаружи только 22, 80, 443 и порт панели; MySQL и Centrifugo слушают localhost. Лишние сервисы панели (exim4, dovecot, proftpd) можно отключить: `systemctl disable --now <unit>`.

## Проверка после развёртывания

- `curl -I https://mlp-evening.ru/` → 200; `/.env`, `/docker-compose.yml`, `/README.md`, `/database.sample.sql`, `/migrations/`, `/deploy/`, `/src/Infra/Env.php` → 403; `/sw.js`, `/manifest.json`, `/assets/...`, `/upload/...` → 200.
- Чат: подключение websocket (`/connection/websocket`), отправка сообщения, ответ бота на упоминание.
- `php tests/run_all.php` на сервере: юниты PASS, интеграционные SKIP (боевая БД в тестах не участвует).

## Обновление кода на проде

`git pull --ff-only && php migrate.php` в корне сайта. Воркер подхватывает новый код на следующей минуте (cron-режим), рестарт не нужен.
