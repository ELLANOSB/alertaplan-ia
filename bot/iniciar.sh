#!/bin/zsh
# Inicia el bot en segundo plano (Mac con FlyEnv). Detener: ./bot/detener.sh
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
PHP_BIN="${PHP_BIN:-$(ls -d $HOME/Library/FlyEnv/app/static-php-8.3*/bin/php 2>/dev/null | head -1)}"
[ -x "$PHP_BIN" ] || PHP_BIN="$(command -v php)"
mkdir -p "$RAIZ/storage"
nohup "$PHP_BIN" "$RAIZ/bot/telegram.php" >> "$RAIZ/storage/telegram.salida.log" 2>&1 &
echo $! > "$RAIZ/storage/telegram.pid"
sleep 3; tail -2 "$RAIZ/storage/telegram.log"
