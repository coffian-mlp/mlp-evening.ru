#!/usr/bin/env bash
# Dedicated disposable environment; never mounts production configuration.
set -euo pipefail
repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
task_dir="$repo_dir/docs/tests/MLP-456/phase3-implementation"
workspace_dir="$task_dir/workspace"
compose() {
  docker compose -p mlp456phase3 -f "$workspace_dir/docker-compose.yml" --env-file "$workspace_dir/.env" -f "$task_dir/compose.yml" "$@"
}
case "${1:-}" in
  up)
    mkdir -p "$workspace_dir"
    rsync -a --exclude='.git' --exclude='.env*' --exclude='config.php' --exclude='centrifugo_config.json' --exclude='docs' --exclude='dev_knowledge' --exclude='.claude' --exclude='.codex' --exclude='.agents' --exclude='node_modules' --exclude='upload' --exclude='logs' "$repo_dir/" "$workspace_dir/"
    touch "$workspace_dir/.mlp456-owned"
    cat > "$workspace_dir/.env" <<'ENV'
DB_HOST=db
DB_NAME=mlp_phase3
DB_USER=mlp_phase3
DB_PASS=synthetic_phase3
DB_ROOT_PASS=synthetic_root_phase3
DB_CHARSET=utf8mb4
CHAT_DRIVER=sse
CENTRIFUGO_API_KEY=
CENTRIFUGO_SECRET=
ENV
    mkdir -p "$workspace_dir/baseline"
    git -C "$repo_dir" archive d8e43ff75a30c0f2ed9be598378ceb782aeb4d54 | tar -x -C "$workspace_dir/baseline"
    cp "$workspace_dir/.env" "$workspace_dir/baseline/.env"
    cat > "$task_dir/compose.yml" <<'YAML'
networks:
  mlp-net:
    internal: true
services:
  db:
    command: ['--sql-mode=STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','--default-time-zone=+00:00','--character-set-server=utf8mb4','--collation-server=utf8mb4_0900_ai_ci']
  php:
    entrypoint: ["docker-php-entrypoint"]
    command: ["php-fpm"]
    ports: ["127.0.0.1:8117:8117"]
    environment:
      PHP_INI_SCAN_DIR: /usr/local/etc/php/conf.d:/var/www/html/tests/helpers
YAML
    mkdir -p "$workspace_dir/logs" "$workspace_dir/upload/"{chat,avatars,lyra}
    compose up -d db php
    compose exec -T -e IT_DB_WAIT=90 php php -r 'require "autoload.php"; $end=time()+90; do {try{$d=new mysqli("db",Infra\Env::get("DB_USER"),Infra\Env::get("DB_PASS"),Infra\Env::get("DB_NAME"));break;}catch(Throwable $e){if(time()>=$end)throw $e;sleep(1);}}while(true);'
    compose exec -T php php bootstrap.php --if-empty --demo
    compose exec -T php php migrate.php
    ;;
  sync)
    for part in src tests migrations database; do rsync -a "$repo_dir/$part/" "$workspace_dir/$part/"; done
    cp "$repo_dir/database.sample.sql" "$workspace_dir/database.sample.sql"
    ;;
  strict|empty) compose exec -T php php tests/helpers/conversation_sql_mode.php "$1" ;;
  test) compose exec -T php php tests/helpers/conversation_network_status.php; compose exec -T -e IT_DB_WAIT=90 php php tests/run_all.php ;;
  serve) compose exec -T php php tests/helpers/conversation_network_status.php; compose exec -d -e MLP_CONVERSATION_PW=1 -e PHP_CLI_SERVER_WORKERS=4 php php -S 0.0.0.0:8117 tests/playwright/mlp-456-conversation-router.php ;;
  down)
    test -f "$workspace_dir/.mlp456-owned"
    compose down -v
    rm -rf -- "$workspace_dir"
    ;;
  *) echo 'Usage: conversation_environment.sh up|sync|strict|empty|test|serve|down' >&2; exit 2 ;;
esac
