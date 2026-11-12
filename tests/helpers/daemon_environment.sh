#!/usr/bin/env bash
# Disposable MLP-461 database outage laboratory. No production files are copied.
set -euo pipefail
repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
task_dir="$repo_dir/docs/tests/MLP-461"
workspace_dir="$task_dir/workspace"
compose() { docker compose -p mlp461 -f "$task_dir/compose.yml" "$@"; }
case "${1:-}" in
  up)
    test ! -e "$workspace_dir"
    mkdir -p "$workspace_dir"
    git -C "$repo_dir" archive HEAD | tar -x -C "$workspace_dir"
    touch "$workspace_dir/.mlp461-owned"
    cp "$repo_dir/tests/test_database_connection_lost.php" "$repo_dir/tests/test_daemon_database_loss.php" "$workspace_dir/tests/"
    cp "$repo_dir/tests/fixtures/daemon-database-loss.php" "$workspace_dir/tests/fixtures/"
    cp "$repo_dir/tests/helpers/daemon_"*.php "$workspace_dir/tests/helpers/"
    cat > "$workspace_dir/.env" <<'ENV'
DB_HOST=db
DB_NAME=mlp461
DB_USER=mlp461
DB_PASS=synthetic_daemon
DB_ROOT_PASS=synthetic_daemon_root
DB_CHARSET=utf8mb4
CHAT_DRIVER=sse
CENTRIFUGO_API_KEY=
CENTRIFUGO_SECRET=
ENV
    chmod 600 "$workspace_dir/.env"
    python3 - "$workspace_dir" <<'PY'
from pathlib import Path
import sys
root=Path(sys.argv[1])
# Keep the real polling client and only supply its supported fake transport seam.
p=root/'src/Domain/AnnouncementSettings.php'
s=p.read_text(); old="return new TelegramBotClient($v['announcements_token'], null, $v['announcements_proxy_url']);"
assert old in s
s=s.replace(old,"return new TelegramBotClient($v['announcements_token'], \\daemonTelegramTransport(...), $v['announcements_proxy_url']);")
p.write_text(s)
# Shared network guard, installed only in this owned disposable copy.
s=(root/'tests/helpers/conversation_network_guard.php').read_text().replace('.mlp456-owned','.mlp461-owned')
(root/'tests/helpers/daemon_network_guard.php').write_text(s)
PY
    cat > "$task_dir/compose.yml" <<YAML
services:
  db:
    image: mysql:8.0
    command: ['--sql-mode=STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','--default-time-zone=+00:00','--character-set-server=utf8mb4','--collation-server=utf8mb4_0900_ai_ci']
    environment:
      MYSQL_DATABASE: mlp461
      MYSQL_USER: mlp461
      MYSQL_PASSWORD: synthetic_daemon
      MYSQL_ROOT_PASSWORD: synthetic_daemon_root
    volumes: [data:/var/lib/mysql]
    networks: [lab]
  php:
    image: ${MLP_DAEMON_PHP_IMAGE:-mlp457-php:latest}
    working_dir: /var/www/html
    entrypoint: [docker-php-entrypoint]
    command: [php-fpm]
    volumes: ["$workspace_dir:/var/www/html"]
    networks: [lab]
networks:
  lab:
    internal: true
volumes:
  data:
YAML
    compose up -d db php
    compose exec -T php php bootstrap.php --if-empty --demo --wait=90
    compose exec -T php php migrate.php
    ;;
  sync)
    test -f "$workspace_dir/.mlp461-owned"
    cp "$repo_dir/src/Infra/Database.php" "$workspace_dir/src/Infra/Database.php"
    cp "$repo_dir/lyra_bot_worker.php" "$repo_dir/stream_monitor_worker.php" "$workspace_dir/"
    ;;
  suite)
    test -f "$workspace_dir/.mlp461-owned"
    compose restart php
    mkdir -p "$workspace_dir/upload/chat" "$workspace_dir/upload/avatars" "$workspace_dir/upload/lyra" "$workspace_dir/logs"
    cp "$repo_dir/tests/helpers/daemon_fixture.php" "$workspace_dir/tests/helpers/"
    compose exec -T php php tests/helpers/daemon_fixture.php suite
    mkdir -p "$workspace_dir/baseline" "$workspace_dir/daemon-ini"
    git -C "$repo_dir" archive d8e43ff75a30c0f2ed9be598378ceb782aeb4d54 | tar -x -C "$workspace_dir/baseline"
    cp "$workspace_dir/.env" "$workspace_dir/baseline/.env"
    cp "$repo_dir/src/Domain/AnnouncementSettings.php" "$workspace_dir/src/Domain/AnnouncementSettings.php"
    cat > "$workspace_dir/daemon-ini/guard.ini" <<'INI'
disable_functions=curl_exec
auto_prepend_file=/var/www/html/tests/helpers/daemon_network_guard.php
INI
    compose exec -T php php bootstrap.php --if-empty --demo --wait=90
    compose exec -T php php migrate.php
    ;;
  test)
    compose exec -T -e IT_DB_WAIT=90 -e IT_FEEDBACK_SCRATCH=1 -e PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/var/www/html/daemon-ini php php tests/run_all.php
    ;;
  exec) shift; compose exec -T php "$@" ;;
  stop-db) compose stop db ;;
  start-db) compose start db ;;
  down)
    test -f "$workspace_dir/.mlp461-owned"
    compose down -v
    rm -rf -- "$workspace_dir"
    ;;
  *) echo 'Usage: daemon_environment.sh up|sync|suite|test|exec <command>|stop-db|start-db|down' >&2; exit 2 ;;
esac
