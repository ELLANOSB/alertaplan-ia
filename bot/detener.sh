#!/bin/zsh
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
[ -f "$RAIZ/storage/telegram.pid" ] && kill "$(cat "$RAIZ/storage/telegram.pid")" 2>/dev/null && rm -f "$RAIZ/storage/telegram.pid" && echo "Bot detenido." || echo "El bot no estaba en ejecución."
