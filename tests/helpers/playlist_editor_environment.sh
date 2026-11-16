#!/usr/bin/env bash
# MLP-472 disposable regression environment; no production config or outbound route.
set -euo pipefail
repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
task_dir="$repo_dir/docs/tests/MLP-472"
workspace_dir="$task_dir/workspace"
compose() { docker compose -p mlp472 --env-file "$task_dir/test.env" -f "$repo_dir/docker-compose.yml" -f "$task_dir/compose.yml" "$@"; }
sync_source() {
  test -f "$workspace_dir/.mlp472-owned"
  (cd "$repo_dir"; git ls-files -z --cached --others --exclude-standard | tar --null -T - -cf -) | tar -xf - -C "$workspace_dir"
}
case "${1:-}" in
  up)
    test ! -e "$workspace_dir"
    mkdir -p "$workspace_dir/docs/tests/MLP-472" "$workspace_dir/upload/"{chat,avatars,lyra} "$workspace_dir/logs"
    touch "$workspace_dir/.mlp472-owned" "$workspace_dir/.mlp468-owned"
    sync_source
    cat > "$task_dir/test.env" <<'ENV'
DB_HOST=db
DB_NAME=mlp_phase3
DB_USER=mlp472
DB_PASS=synthetic472
DB_ROOT_PASS=synthetic_root472
DB_CHARSET=utf8mb4
CHAT_DRIVER=sse
CENTRIFUGO_API_KEY=
CENTRIFUGO_SECRET=
ENV
    cp "$task_dir/test.env" "$workspace_dir/.env"
    # Existing memory/drawing regression probes require these exact historical sources.
    mkdir -p "$workspace_dir/baseline" "$workspace_dir/baseline-red"
    git -C "$repo_dir" archive d8e43ff75a30c0f2ed9be598378ceb782aeb4d54 | tar -xf - -C "$workspace_dir/baseline"
    git -C "$repo_dir" archive 4fdbfec8d29a808126db29fb491681d3c0dff657 | tar -xf - -C "$workspace_dir/baseline-red"
    cp "$workspace_dir/.env" "$workspace_dir/baseline/.env"
    cp "$workspace_dir/.env" "$workspace_dir/baseline-red/.env"
    cat > "$task_dir/compose.yml" <<YAML
networks:
  mlp-net:
    internal: true
services:
  php:
    image: ${MLP472_PHP_IMAGE:-mlp457-php:latest}
    entrypoint: [docker-php-entrypoint]
    command: [php-fpm]
    volumes: ["$workspace_dir:/var/www/html"]
    ports: ['127.0.0.1:8173:8173']
YAML
    compose up -d db php
    compose exec -T php php bootstrap.php --if-empty --demo --wait=90
    compose exec -T php php migrate.php
    ;;
  sync) sync_source ;;
  strict|empty) compose exec -T php php tests/helpers/conversation_sql_mode.php "$1" ;;
  test) compose exec -T -e IT_DB_WAIT=90 -e IT_FEEDBACK_SCRATCH=1 php php tests/run_all.php ;;
  serve) compose exec -d -e PHP_CLI_SERVER_WORKERS=4 php php -S 0.0.0.0:8173 -t /var/www/html ;;
  exec) shift; compose exec -T php "$@" ;;
  down)
    test -f "$workspace_dir/.mlp472-owned"
    compose down -v
    rm -rf -- "$workspace_dir"
    ;;
  *) echo 'Usage: playlist_editor_environment.sh up|sync|strict|empty|test|serve|exec <command>|down' >&2; exit 2 ;;
esac
