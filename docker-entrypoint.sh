#!/bin/bash
set -e

# Remove any enabled MPM modules so only prefork remains
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf || true

a2dismod mpm_event mpm_worker || true

a2enmod mpm_prefork rewrite || true

exec docker-php-entrypoint "$@"
