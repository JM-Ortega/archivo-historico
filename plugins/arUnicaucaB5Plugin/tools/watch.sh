#!/usr/bin/env bash
# Watch DEV opt-in del theme arUnicaucaB5Plugin. Se ejecuta DENTRO de la imagen AtoM (servicio `theme_watch` de
# compose.yaml, profile `tools`; no entra en `docker compose up`):
#
#   docker compose run --rm theme_watch
#
# Usa el watch nativo del toolchain AtoM (Webpack watch, el mismo comando que expone "npm run watch" en
# package.json) sobre el mismo boundary de escritura que `theme_build` (plugin RW + theme_dist RW): detección nativa
# de cambios en SCSS/JS, sin polling de filesystem.
#
# Ownership: igual que build.sh, el contenedor corre como root, así que templates/_layout_start.php nacería
# root:root en el checkout del host. Usa la MISMA referencia de ownership que build.sh (stat del directorio
# `templates/` que lo contiene, no un UID/GID fijo): en el checkout del host ese directorio y el template fuente
# `_layout_start_webpack.php` que contiene pertenecen siempre al mismo usuario, así que ambas referencias coinciden
# en la práctica; watch.sh solo repite el mismo cálculo que build.sh ya usa. A diferencia de build.sh (one-shot),
# aquí Webpack puede eliminar y recrear ese fichero en CADA rebuild mientras dura la sesión (HtmlWebpackPlugin lo
# reescribe con cada compilación), así que la corrección no puede aplicarse solo al salir: un companion loop ligero,
# en paralelo a Webpack watch, la mantiene durante toda la sesión, solo cuando difiere del actual (sin UID/GID
# fijos, sin sudo del host, sin chmod). Ctrl+C (o `stop`/`down`) detiene watch y companion sin dejar procesos
# huérfanos y aplica una corrección final antes de salir.
set -uo pipefail

ATOM_SRC="${ATOM_SRC:-/atom/src}"
PLUGIN=arUnicaucaB5Plugin
TEMPLATES_DIR="$ATOM_SRC/plugins/$PLUGIN/templates"
PARTIAL="$TEMPLATES_DIR/_layout_start.php"
# Intervalo fijo (no configurable): un valor de entrada sin validar podría convertir el companion en un loop
# agresivo. 1 s es suficiente para que la corrección sea prácticamente inmediata frente a un rebuild (~15-20 s).
readonly OWNERSHIP_INTERVAL=1

log() { echo "theme-watch: $*"; }

# Corrige el ownership del partial si existe y difiere del directorio que lo contiene. No toca nada si ya coincide.
fix_ownership_once() {
  [[ -e "$PARTIAL" ]] || return 0
  local owner current
  owner="$(stat -c '%u:%g' "$TEMPLATES_DIR")" || return 1
  current="$(stat -c '%u:%g' "$PARTIAL")" || return 1
  [[ "$current" == "$owner" ]] && return 0
  chown "$owner" "$PARTIAL"
}

ownership_loop() {
  while true; do
    fix_ownership_once || log "WARN: no se pudo corregir el ownership de $PARTIAL"
    sleep "$OWNERSHIP_INTERVAL"
  done
}

WATCH_PID=""
LOOP_PID=""
CLEANED_UP=0

cleanup() {
  local rc="${1:-$?}"
  ((CLEANED_UP)) && return
  CLEANED_UP=1
  trap - EXIT INT TERM QUIT
  [[ -n "$WATCH_PID" ]] && kill "$WATCH_PID" 2>/dev/null
  [[ -n "$LOOP_PID" ]] && kill "$LOOP_PID" 2>/dev/null
  [[ -n "$WATCH_PID" ]] && wait "$WATCH_PID" 2>/dev/null
  [[ -n "$LOOP_PID" ]] && wait "$LOOP_PID" 2>/dev/null
  if fix_ownership_once; then
    log "ownership final OK"
  else
    log "ERROR: no se pudo corregir el ownership de $PARTIAL al salir"
    ((rc != 0)) || rc=1
  fi
  log "detenido"
  exit "$rc"
}
trap 'cleanup $?' EXIT
trap 'cleanup 130' INT
trap 'cleanup 143' TERM
# La imagen AtoM declara STOPSIGNAL SIGQUIT (upstream, no se toca): `docker stop`/`docker compose down` envían QUIT,
# no TERM. Sin este trap, ese camino de parada no limpiaría (SIGKILL tras el timeout de gracia).
trap 'cleanup 131' QUIT

[[ -d "$TEMPLATES_DIR" ]] || { log "ERROR: no existe $TEMPLATES_DIR (¿falta el bind del plugin?)"; exit 66; }

cd "$ATOM_SRC" || exit 66

log "companion de ownership activo (intervalo ${OWNERSHIP_INTERVAL}s)"
ownership_loop &
LOOP_PID=$!

log "webpack watch ($ATOM_SRC)"
# Se invoca el binario de Webpack directamente (mismo comando que expone "npm run watch" en package.json) en vez de
# a través de "npm run watch": así el proceso vigilado es el propio Webpack, sin la capa intermedia de npm, cuyo
# reenvío de señales a su hijo demostró no ser fiable para un shutdown limpio por TERM/INT.
node_modules/.bin/webpack watch &
WATCH_PID=$!

wait "$WATCH_PID"
