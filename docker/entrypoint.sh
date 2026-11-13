#!/bin/sh
# Arranque en contenedor: genera .env desde variables del servicio, prepara la base y lanza web o bot.
set -e
cd /app
cat > .env <<ENV
APP_TZ=${APP_TZ:-America/Lima}
DB_HOST=${DB_HOST:-${MYSQLHOST:-127.0.0.1}}
DB_PORT=${DB_PORT:-${MYSQLPORT:-3306}}
DB_DATABASE=${DB_DATABASE:-${MYSQLDATABASE:-alertaplan_ia}}
DB_USERNAME=${DB_USERNAME:-${MYSQLUSER:-alertaplan}}
DB_PASSWORD=${DB_PASSWORD:-${MYSQLPASSWORD:-}}
IA_MODO=${IA_MODO:-auto}
ANTHROPIC_API_KEY=${ANTHROPIC_API_KEY:-}
ANTHROPIC_MODEL=${ANTHROPIC_MODEL:-claude-haiku-4-5}
TELEGRAM_BOT_TOKEN=${TELEGRAM_BOT_TOKEN:-}
ENV
chmod 640 .env && chown root:www-data .env
mkdir -p storage && chown -R www-data:www-data storage

php docker/preparar.php "${APP_ROLE:-web}"

if [ "${APP_ROLE:-web}" = "bot" ]; then
  exec php bot/telegram.php
fi

PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
