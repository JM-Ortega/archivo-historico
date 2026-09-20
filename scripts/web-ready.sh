#!/usr/bin/env bash
# Readiness de aplicación de la web DEV: distingue "contenedor running" de "AtoM responde".
#
#   scripts/web-ready.sh          # una comprobación
#   scripts/web-ready.sh --wait   # reintenta hasta WEB_READY_ATTEMPTS x WEB_READY_INTERVAL s
#
# READY = GET / -> HTTP 200 (la portada se renderiza contra la BD, sin errores)
#         + cabecera `Set-Cookie: atom_culture=`.
#
# Marcador: `atom_culture` lo emite AtoM core en cada respuesta (apps/qubit/config/qubitConfiguration.class.php),
# con nombre fijo; no depende del título del sitio, del tema, de fixtures ni del HTML. Un 200 estático de Nginx
# (p. ej. /robots.txt) o de cualquier otro servidor no lo lleva.
#
# Salida: 0 READY, 1 no READY (el motivo se imprime en stderr).
set -euo pipefail

URL="${ATOM_WEB_URL:-http://localhost:${ATOM_WEB_PORT:-8080}}"
ATTEMPTS="${WEB_READY_ATTEMPTS:-30}"
INTERVAL="${WEB_READY_INTERVAL:-2}"

check() {
  local headers status
  headers="$(curl -sS --max-time 20 -o /dev/null -D - "$URL/" 2>&1)" || { echo "web-ready: sin respuesta de $URL/" >&2; return 1; }
  status="$(head -n1 <<<"$headers" | awk '{print $2}')"
  if [[ "$status" != 200 ]]; then echo "web-ready: HTTP $status (esperado 200)" >&2; return 1; fi
  if ! grep -qi '^set-cookie: atom_culture=' <<<"$headers"; then echo "web-ready: 200 sin marcador AtoM (cookie atom_culture)" >&2; return 1; fi
}

if [[ "${1:-}" == "--wait" ]]; then
  for ((i = 1; i <= ATTEMPTS; i++)); do
    if check 2>/dev/null; then echo "web-ready: READY ($URL)"; exit 0; fi
    sleep "$INTERVAL"
  done
  check || true
  echo "web-ready: NOT READY tras $ATTEMPTS intentos ($URL)" >&2
  exit 1
fi

check && echo "web-ready: READY ($URL)"
