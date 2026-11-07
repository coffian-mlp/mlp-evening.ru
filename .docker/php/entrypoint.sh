#!/bin/sh
set -eu
php /var/www/html/bootstrap.php --if-empty --demo --wait=90
exec docker-php-entrypoint "$@"
