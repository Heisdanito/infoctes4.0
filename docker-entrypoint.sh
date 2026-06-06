#!/bin/bash
set -e

chown -R www-data:www-data /var/www/html || true

envsubst '$PORT' < /etc/nginx/sites-available/default.template > /etc/nginx/sites-available/default

php-fpm -D
nginx -g 'daemon off;'
