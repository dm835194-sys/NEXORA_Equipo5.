#!/bin/sh
set -eu
php /app/docker/wait-for-database.php
php artisan migrate --force
php artisan db:seed --class=DemoSeeder --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/turnolab.conf
