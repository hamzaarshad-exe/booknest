#!/bin/sh
set -eu

mkdir -p /var/lib/booknest/sessions
chmod 0700 /var/lib/booknest /var/lib/booknest/sessions
php /usr/local/lib/booknest/bootstrap.php
chown -R www-data:www-data /var/lib/booknest

exec docker-php-entrypoint "$@"
