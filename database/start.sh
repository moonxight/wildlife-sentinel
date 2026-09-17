#!/bin/sh
set -eu
php /var/www/html/wildlife-sentinel/database/setup-postgres.php

# Render supplies PORT at runtime; keep Apache's local default when it is absent.
if [ -n "${PORT:-}" ] && [ "$PORT" != "80" ]; then
    sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s#:\?80>#:${PORT}>#g" /etc/apache2/sites-available/000-default.conf
fi

exec apache2-foreground
