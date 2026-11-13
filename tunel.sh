#!/bin/zsh
# Plan B: enlace público temporal al sistema local (FlyEnv) con Cloudflare Quick Tunnel.
# Requiere: brew install cloudflared · El enlace cambia en cada ejecución y funciona mientras la Mac esté prendida.
PUERTO="${1:-8091}"
command -v cloudflared >/dev/null || { echo "Instala primero: brew install cloudflared"; exit 1; }
echo "Abriendo túnel hacia http://127.0.0.1:${PUERTO} … (Ctrl+C para cerrar)"
cloudflared tunnel --no-autoupdate --url "http://127.0.0.1:${PUERTO}" 2>&1 | while read -r l; do
  echo "$l" | grep -Eo 'https://[a-z0-9-]+\.trycloudflare\.com' && echo "↑ Comparte ese enlace" ; done
