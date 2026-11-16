#!/usr/bin/env bash
# Disposable synthetic MLP-468 laboratory; no production configuration or outbound route.
set -euo pipefail
repo_dir="$(cd "$(dirname "$0")/../.." && pwd)"
task_dir="$repo_dir/docs/tests/MLP-468"
workspace_dir="$task_dir/workspace"
compose() { docker compose -p mlp468 -f "$task_dir/compose.yml" "$@"; }
sync_source() {
    test -f "$workspace_dir/.mlp468-owned"
    # Git's tracked/new source list excludes private configuration and ignored artifacts.
    git -C "$repo_dir" ls-files -z --cached --others --exclude-standard | tar --null -T - -cf - -C "$repo_dir" 2>/dev/null | tar -xf - -C "$workspace_dir"
}
case "${1:-}" in
  up)
    test ! -e "$workspace_dir"
    mkdir -p "$workspace_dir"
    git -C "$repo_dir" archive HEAD | tar -x -C "$workspace_dir"
    touch "$workspace_dir/.mlp468-owned"
    sync_source
    cat > "$workspace_dir/.env" <<'ENV'
DB_HOST=db
DB_NAME=mlp_phase3
DB_USER=mlp468
DB_PASS=synthetic_drawing
DB_ROOT_PASS=synthetic_drawing_root
DB_CHARSET=utf8mb4
CHAT_DRIVER=sse
CENTRIFUGO_API_KEY=
CENTRIFUGO_SECRET=
ENV
    chmod 600 "$workspace_dir/.env"
    mkdir -p "$workspace_dir/baseline" "$workspace_dir/upload/chat" "$workspace_dir/upload/avatars" "$workspace_dir/upload/lyra" "$workspace_dir/logs"
    git -C "$repo_dir" archive d8e43ff75a30c0f2ed9be598378ceb782aeb4d54 | tar -x -C "$workspace_dir/baseline"
    cp "$workspace_dir/.env" "$workspace_dir/baseline/.env"
    cat > "$task_dir/compose.yml" <<YAML
services:
  db:
    image: mysql:8.0
    command: ['--sql-mode=STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION','--default-time-zone=+00:00','--character-set-server=utf8mb4','--collation-server=utf8mb4_0900_ai_ci']
    environment:
      MYSQL_DATABASE: mlp_phase3
      MYSQL_USER: mlp468
      MYSQL_PASSWORD: synthetic_drawing
      MYSQL_ROOT_PASSWORD: synthetic_drawing_root
    volumes: [data:/var/lib/mysql]
    networks: [lab]
  php:
    image: mlp457-php:latest
    working_dir: /var/www/html
    entrypoint: [docker-php-entrypoint]
    command: [php-fpm]
    ports: ['127.0.0.1:8468:8468']
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
  sync) sync_source ;;
  red)
    test -f "$workspace_dir/.mlp468-owned"
    baseline_dir="$workspace_dir/baseline-red"
    if test ! -e "$baseline_dir"; then
      mkdir -p "$baseline_dir"
      git -C "$repo_dir" archive 4fdbfec8d29a808126db29fb491681d3c0dff657 | tar -x -C "$baseline_dir"
    else
      test -f "$baseline_dir/.mlp468-owned"
    fi
    cp "$workspace_dir/.env" "$baseline_dir/.env"
    touch "$baseline_dir/.mlp468-owned"
    cp "$repo_dir/tests/test_image_generator_retries.php" "$repo_dir/tests/integration_drawing_worker_concurrency.php" "$baseline_dir/tests/"
    cp "$repo_dir/tests/helpers/drawing_"*.php "$baseline_dir/tests/helpers/"
    set +e
    compose exec -T php php baseline-red/tests/test_image_generator_retries.php
    transport_status=$?
    compose exec -T php php baseline-red/tests/integration_drawing_worker_concurrency.php
    worker_status=$?
    set -e
    echo "Baseline RED exit codes: transport=$transport_status worker=$worker_status"
    test "$transport_status" -eq 1
    test "$worker_status" -eq 1
    ;;
  exec) shift; compose exec -T php "$@" ;;
  strict|empty) compose exec -T php php tests/helpers/conversation_sql_mode.php "$1" ;;
  serve) compose exec -d -e PHP_CLI_SERVER_WORKERS=8 php php -S 0.0.0.0:8468 tests/playwright/mlp-468-router.php ;;
  test) compose exec -T -e IT_DB_WAIT=90 -e IT_FEEDBACK_SCRATCH=1 php php tests/run_all.php ;;
  down)
    test -f "$workspace_dir/.mlp468-owned"
    compose down -v
    rm -rf -- "$workspace_dir"
    ;;
  *) echo 'Usage: drawing_environment.sh up|sync|red|strict|empty|serve|exec <command>|test|down' >&2; exit 2 ;;
esac
