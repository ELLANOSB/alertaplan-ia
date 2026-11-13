#!/bin/zsh
# AlertaPlan IA · instalación en macOS con FlyEnv (PHP 8.3 + MySQL 8.4 + Apache)
# Uso:  ./instalar.sh            (pide la contraseña root de MySQL)
#       MYSQL_ROOT_PASS=xxx ./instalar.sh
set -e
RAIZ="$(cd "$(dirname "$0")" && pwd)"
FLY="$HOME/Library/FlyEnv"
PHP_BIN="${PHP_BIN:-$(ls -d $FLY/app/static-php-8.3*/bin/php 2>/dev/null | head -1)}"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php)"
MYSQL_BIN="${MYSQL_BIN:-$(ls -d $FLY/app/mysql-8*/bin/mysql 2>/dev/null | head -1)}"
[ -x "$MYSQL_BIN" ] || MYSQL_BIN="$(command -v mysql)"
echo "PHP:   $PHP_BIN"; echo "MySQL: $MYSQL_BIN"
if [ -z "${MYSQL_ROOT_PASS+x}" ]; then read -s "MYSQL_ROOT_PASS?Contraseña root de MySQL (FlyEnv): "; echo; fi

[ -f "$RAIZ/.env" ] || cp "$RAIZ/.env.example" "$RAIZ/.env"
DBP=$(grep '^DB_PASSWORD=' "$RAIZ/.env" | cut -d= -f2-)
echo "→ Creando base de datos y usuario…"
"$MYSQL_BIN" -uroot -p"$MYSQL_ROOT_PASS" -h127.0.0.1 -P3306 2>/dev/null <<SQL
CREATE DATABASE IF NOT EXISTS alertaplan_ia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'alertaplan'@'localhost' IDENTIFIED BY '$DBP';
CREATE USER IF NOT EXISTS 'alertaplan'@'127.0.0.1' IDENTIFIED BY '$DBP';
GRANT ALL PRIVILEGES ON alertaplan_ia.* TO 'alertaplan'@'localhost';
GRANT ALL PRIVILEGES ON alertaplan_ia.* TO 'alertaplan'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
echo "→ Tablas y datos de demostración…"
"$PHP_BIN" "$RAIZ/database/instalar.php"
echo "→ Pruebas automatizadas…"
"$PHP_BIN" "$RAIZ/tests/pruebas.php" | tail -4
"$PHP_BIN" "$RAIZ/database/instalar.php" >/dev/null   # deja la demo limpia después de las pruebas

# Sitio en el Apache de FlyEnv (puerto 8091)
CONF_DIR="$FLY/server/apache/common/conf"
MAIN=$(ps -axo command | grep -o "$CONF_DIR/[a-f0-9]*\.conf" | head -1)
if [ -n "$MAIN" ] && [ -f "$MAIN" ]; then
  sed -e "s#__RAIZ__#$RAIZ#g" -e "s#__LOGS__#$FLY/server/apache/common/logs#g" "$RAIZ/flyenv/alertaplan-ia-apache.conf" > "$CONF_DIR/alertaplan-ia-local.conf"
  if ! grep -q "alertaplan-ia-local.conf" "$MAIN"; then
    cp "$MAIN" "$MAIN.bak-alertaplan"
    printf '\n# AlertaPlan IA · Diplomado IA AECODE (puerto 8091)\nInclude "%s"\n' "$CONF_DIR/alertaplan-ia-local.conf" >> "$MAIN"
  fi
  kill -USR1 "$(cat "$FLY/server/apache/httpd.pid")" 2>/dev/null && sleep 2
  echo "✓ Listo: http://127.0.0.1:8091"
else
  echo "Apache de FlyEnv no está encendido. Enciéndelo y vuelve a ejecutar, o usa:"
  echo "   $PHP_BIN -S 127.0.0.1:8091 -t \"$RAIZ/public\""
fi
echo "Usuarios: planeamiento@claveles.demo / produccion@claveles.demo / gerencia@claveles.demo · clave Aecode2026!"
