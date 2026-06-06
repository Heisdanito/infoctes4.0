#!/bin/bash
set -e

# Remove any enabled MPM modules so only prefork remains
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf || true

a2dismod mpm_event mpm_worker || true

a2enmod mpm_prefork rewrite || true

echo "=== Apache MPM debug ==="
ls -la /etc/apache2/mods-enabled | sort
printf "\n=== MPM load files ===\n"
for f in /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf; do
  if [ -e "$f" ]; then
    echo "--- $f ---"
    cat "$f"
  fi
done
printf "\n=== LoadModule MPM lines ===\n"
grep -R --line-number "LoadModule .*mpm_" /etc/apache2 || true
printf "\n=== Apache modules enabled (mpm only) ===\n"
apachectl -M 2>/dev/null | grep mpm || true

echo "=== End Apache MPM debug ==="

exec docker-php-entrypoint "$@"
