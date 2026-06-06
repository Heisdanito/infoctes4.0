#!/bin/bash
set -e

chown -R www-data:www-data /var/www/html || true

php-fpm -D
nginx -g 'daemon off;'
