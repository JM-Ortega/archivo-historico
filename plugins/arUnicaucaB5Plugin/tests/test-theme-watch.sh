#!/usr/bin/env bash
# Prueba de integración de theme_watch (Docker Compose DEV, profile `tools`).
#
#   plugins/arUnicaucaB5Plugin/tests/test-theme-watch.sh
#
# Cubre: estructura Compose (opt-in/profile tools, fuera del runtime default, mismo boundary de escritura que
# theme_build, sin puerto/depends_on/full bind de /atom/src), watch funcional (arranque, un cambio SCSS provoca
# rebuild, un cambio JS provoca rebuild, varios cambios en la misma sesión sin reiniciar, output servido por
# Nginx), ownership continuo de templates/_layout_start.php con la MISMA referencia que build.sh (el directorio
# templates/ del host) — incluida su recreación tras borrarlo con watch activo — y lifecycle (start/rebuild/stop
# sin residuos/restart sin RESET/rebuild de nuevo), cerrando con un theme_build one-shot para demostrar que sigue
# siendo independiente.
#
# Opera sobre el proyecto Compose seleccionado por el entorno estándar (COMPOSE_FILE / COMPOSE_PROJECT_NAME /
# ATOM_WEB_PORT); por defecto la DEV real. No destruye estado (nunca `down -v`). Cambios temporales que hace y
# revierte (trap): ediciones de scss/js del plugin y el contenedor temporal de watch; termina reconstruyendo el
# theme desde el source restaurado. Requiere el runtime arriba (`docker compose up -d --wait`). No requiere
# intervención manual.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
# --progress quiet: `run` re-verifica el grafo de build (additional_contexts: atom_upstream) en cada
# invocación; sin TTY ese trazo (aunque cacheado) sale por stdout y contamina FACTS de abajo, cuyo
# `grep -c '='` (sin anclar) cuenta CUALQUIER "=" en la salida capturada, no solo las de las facts reales.
# Reproducido en WSL/Linux, no es un workaround de Git Bash/MSYS.
COMPOSE=(docker compose --progress quiet)
PLUGIN=arUnicaucaB5Plugin
PDIR="plugins/$PLUGIN"
PIN=/atom/src/plugins/$PLUGIN
PORT="${ATOM_WEB_PORT:-8080}"
URL="${ATOM_WEB_URL:-http://127.0.0.1:$PORT}"
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
TMP="$(mktemp -d)"
PARTIAL="$PDIR/templates/_layout_start.php"
CNAME="theme_watch_test_$$"
fails=0
DIRTY=0
WATCH_BGPID=""
CUR_LOG=""

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:90}"; else echo "FAIL  $1 -> ${3:0:300} (esperado ${2:0:300})"; fails=$((fails + 1)); fi
}
expect_ne() { # <descripción> <no esperado> <resultado>
  if [[ "$3" != "$2" ]]; then echo "PASS  $1 -> ${3:0:90}"; else echo "FAIL  $1 -> ${3:0:300} (no debía ser ${2:0:300})"; fails=$((fails + 1)); fi
}

host_owner() { stat -c '%u:%g' "$1"; }
code() { curl -sS --max-time 20 -o /dev/null -w '%{http_code}' "$@"; }
refs_of_partial() { grep -oE '/dist/[^"'\''<> ]+\.(js|css)' "$PARTIAL" | sort -u; }
css_ref() { refs_of_partial | grep -E "/css/$PLUGIN\.bundle\." | awk 'NR==1'; }
js_ref() { refs_of_partial | grep -E "/js/$PLUGIN\.bundle\." | awk 'NR==1'; }

# Deja el contenedor de watch (si sigue vivo) parado y sin residuos. Idempotente.
stop_watch_container() {
  docker kill --signal=INT "$CNAME" >/dev/null 2>&1 || true
  [[ -n "$WATCH_BGPID" ]] && wait "$WATCH_BGPID" 2>/dev/null
  WATCH_BGPID=""
  docker rm -f "$CNAME" >/dev/null 2>&1 || true
}

cleanup() {
  local rc=$?
  trap - EXIT INT TERM
  set +e
  stop_watch_container
  for f in scss/_foundation-marker.scss js/main.js; do
    [[ -f "$TMP/$(basename "$f").orig" ]] && cp "$TMP/$(basename "$f").orig" "$PDIR/$f"
  done
  if ((DIRTY)); then
    "${COMPOSE[@]}" run --rm -T theme_build >"$TMP/final-build.log" 2>&1 \
      || { echo "WARN  el build final de restauración falló:"; tail -n 20 "$TMP/final-build.log"; }
  fi
  rm -rf "$TMP"
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

backup() { cp "$PDIR/$1" "$TMP/$(basename "$1").orig"; DIRTY=1; }

# Lanza theme_watch en background, con un log propio por sesión (permite reiniciar y contar compiles desde 0).
start_watch() {
  docker rm -f "$CNAME" >/dev/null 2>&1 || true
  CUR_LOG="$(mktemp -p "$TMP")"
  "${COMPOSE[@]}" run --rm --name "$CNAME" theme_watch >"$CUR_LOG" 2>&1 &
  WATCH_BGPID=$!
}

# <n> <timeout_s> → 0 si el log de la sesión actual acumula >= n compiles de Webpack dentro del timeout.
# `grep -c` ya imprime "0" (con exit != 0) cuando no hay coincidencias: NO se usa "|| echo 0" (duplicaría la salida
# y rompería la expresión aritmética); solo "|| true" para que el pipeline no aborte con set -e en otros contextos.
wait_for_compile_count() {
  local end=$((SECONDS + $2)) n
  while ((SECONDS < end)); do
    n="$(grep -c 'webpack .* compiled' "$CUR_LOG" 2>/dev/null || true)"
    ((${n:-0} >= $1)) && return 0
    sleep 1
  done
  return 1
}

# <fichero> <dir-referencia> <timeout_s> → 0 si el ownership de <fichero> converge al de <dir-referencia> dentro del
# timeout. El companion de ownership corre en su propio ciclo (~1 s), independiente de la línea de log del compile:
# tras un rebuild puede haber una ventana breve y acotada antes de su siguiente iteración. Este helper valida esa
# convergencia acotada (contrato real: "se mantiene continuamente", no "cambia atómicamente con el compile").
wait_for_ownership_match() {
  local end=$((SECONDS + $3))
  while ((SECONDS < end)); do
    [[ -e "$1" ]] && [[ "$(host_owner "$1")" == "$(host_owner "$2")" ]] && return 0
    sleep 1
  done
  return 1
}

echo "== A. Estructura Compose de theme_watch (opt-in, boundary de escritura, sin puerto/depends_on/full bind) =="
read -r -d '' FACTS_PHP <<'PHP' || true
$c = json_decode(stream_get_contents(STDIN), true)['services'];
$m = fn($v) => empty($v['read_only']) ? 'rw' : 'ro';
$vols = function ($s) use ($c, $m) {
    $o = [];
    foreach ($c[$s]['volumes'] ?? [] as $v) { $o[] = $v['target'].' '.$m($v); }
    sort($o);
    return implode(';', $o);
};
$fullSrcBind = false;
foreach ($c['theme_watch']['volumes'] ?? [] as $v) { if ($v['target'] === '/atom/src') { $fullSrcBind = true; } }
$out = [
    'image_atom' => $c['atom']['image'],
    'image_theme_watch' => $c['theme_watch']['image'],
    'theme_watch_vols' => $vols('theme_watch'),
    'theme_watch_profiles' => implode(',', $c['theme_watch']['profiles'] ?? []),
    'theme_watch_ports' => count($c['theme_watch']['ports'] ?? []),
    'theme_watch_deps' => count($c['theme_watch']['depends_on'] ?? []),
    'theme_watch_full_atom_src_bind' => (int) $fullSrcBind,
];
foreach ($out as $k => $v) { echo $k, '=', $v, "\n"; }
PHP
FACTS="$("${COMPOSE[@]}" --profile tools config --format json | "${COMPOSE[@]}" run --rm -T --no-deps --entrypoint php theme_watch -r "$FACTS_PHP" 2>/dev/null)"
fact() { grep "^$1=" <<<"$FACTS" | cut -d= -f2- || true; }
expect "el análisis de la configuración Compose produjo resultados" "7" "$(grep -c '=' <<<"$FACTS" || true)"
expect "theme_watch usa la misma imagen que atom" "$(fact image_atom)" "$(fact image_theme_watch)"
expect "theme_watch: plugin RW + theme_dist RW en /atom/src/dist" "/atom/src/dist rw;$PIN rw" "$(fact theme_watch_vols)"
expect "theme_watch es opt-in (profile tools)" "tools" "$(fact theme_watch_profiles)"
expect "theme_watch sin puertos" "0" "$(fact theme_watch_ports)"
expect "theme_watch sin depends_on" "0" "$(fact theme_watch_deps)"
expect "theme_watch no monta /atom/src completo" "0" "$(fact theme_watch_full_atom_src_bind)"
expect "theme_watch NO aparece en el runtime default (sin --profile tools)" "0" "$("${COMPOSE[@]}" config --services | grep -cx theme_watch || true)"

echo "== B. Watch funcional: arranque, SCSS, JS, repetición en la misma sesión =="
backup scss/_foundation-marker.scss
backup js/main.js
start_watch
RC=0; wait_for_compile_count 1 40 || RC=1
expect "theme_watch: primer compile completado" "0" "$RC"
RC=0; wait_for_ownership_match "$PARTIAL" "$PDIR/templates" 5 || RC=1
expect "ownership tras el primer compile converge a la referencia de build.sh (dir templates/, ≤5s)" "0" "$RC"

OLD_CSS="$(css_ref)"
printf '\nbody { --theme-watch-test: "%s"; }\n' "$RUN" >>"$PDIR/scss/_foundation-marker.scss"
RC=0; wait_for_compile_count 2 40 || RC=1
expect "SCSS: rebuild detectado tras editar" "0" "$RC"
NEW_CSS="$(css_ref)"
expect_ne "SCSS: el partial referencia un bundle CSS nuevo (hash distinto)" "$OLD_CSS" "$NEW_CSS"
expect "SCSS: Nginx sirve el bundle nuevo" "200" "$(code "$URL$NEW_CSS")"
expect "SCSS: el bundle nuevo contiene la edición" "1" "$(curl -sS "$URL$NEW_CSS" | grep -c -- "--theme-watch-test: \"$RUN\"" || true)"
expect "SCSS: el bundle anterior ya no existe (clean: true)" "404" "$(code "$URL$OLD_CSS")"
RC=0; wait_for_ownership_match "$PARTIAL" "$PDIR/templates" 5 || RC=1
expect "SCSS: ownership tras el rebuild converge a la referencia de build.sh (dir templates/, ≤5s)" "0" "$RC"

OLD_JS="$(js_ref)"
printf '\nconsole.log("theme-watch-test-js-%s");\n' "$RUN" >>"$PDIR/js/main.js"
RC=0; wait_for_compile_count 3 40 || RC=1
expect "JS: rebuild detectado tras editar (misma sesión, sin reiniciar el watcher)" "0" "$RC"
NEW_JS="$(js_ref)"
expect_ne "JS: el partial referencia un bundle JS nuevo (hash distinto)" "$OLD_JS" "$NEW_JS"
expect "JS: Nginx sirve el bundle nuevo" "200" "$(code "$URL$NEW_JS")"
expect "JS: el bundle nuevo contiene la edición" "1" "$(curl -sS "$URL$NEW_JS" | grep -c "theme-watch-test-js-$RUN" || true)"
expect "JS: el bundle anterior ya no existe" "404" "$(code "$URL$OLD_JS")"

echo "== C. Ownership crítico: recreación de _layout_start.php con watch activo =="
rm -f "$PARTIAL"
expect "precondición: el partial fue eliminado" "no" "$([[ -e $PARTIAL ]] && echo yes || echo no)"
printf '\nconsole.log("theme-watch-test-js2-%s");\n' "$RUN" >>"$PDIR/js/main.js"
RC=0; wait_for_compile_count 4 40 || RC=1
expect "rebuild tras borrar el partial se completa" "0" "$RC"
expect "el rebuild siguiente recrea el partial" "yes" "$([[ -e $PARTIAL ]] && echo yes || echo no)"
RC=0; wait_for_ownership_match "$PARTIAL" "$PDIR/templates" 5 || RC=1
expect "ownership recreado converge a la MISMA referencia que build.sh (dir templates/, ≤5s)" "0" "$RC"
expect "el usuario del host puede volver a manipular el partial (RW)" "ok" "$(rm -f "$PARTIAL" && [[ ! -e "$PARTIAL" ]] && echo ok || echo no)"

echo "== D. Lifecycle: stop limpio sin residuos, restart sin RESET, rebuild de nuevo =="
docker kill --signal=INT "$CNAME" >/dev/null 2>&1
wait "$WATCH_BGPID"; RC=$?
WATCH_BGPID=""
expect "Ctrl+C (SIGINT): shutdown limpio, exit 130" "130" "$RC"
expect "sin contenedor de watch residual" "0" "$(docker ps -a --filter "name=$CNAME" --format '{{.Names}}' | wc -l)"

start_watch
RC=0; wait_for_compile_count 1 40 || RC=1
expect "restart sin RESET: primer compile del nuevo proceso" "0" "$RC"
OLD_CSS2="$(css_ref)"
printf '\nbody { --theme-watch-test2: "%s"; }\n' "$RUN" >>"$PDIR/scss/_foundation-marker.scss"
RC=0; wait_for_compile_count 2 40 || RC=1
expect "restart: rebuild vuelve a funcionar" "0" "$RC"
NEW_CSS2="$(css_ref)"
expect_ne "restart: el bundle cambia de nuevo" "$OLD_CSS2" "$NEW_CSS2"

docker kill --signal=INT "$CNAME" >/dev/null 2>&1
wait "$WATCH_BGPID"; RC=$?
WATCH_BGPID=""
expect "segundo stop: shutdown limpio, exit 130" "130" "$RC"
expect "sin contenedor de watch residual (segunda vez)" "0" "$(docker ps -a --filter "name=$CNAME" --format '{{.Names}}' | wc -l)"

echo "== E. docker stop (STOPSIGNAL SIGQUIT de la imagen AtoM) también limpia =="
start_watch
RC=0; wait_for_compile_count 1 40 || RC=1
expect "sesión para probar SIGQUIT: primer compile completado" "0" "$RC"
T0=$SECONDS
docker stop -t 10 "$CNAME" >/dev/null 2>&1
wait "$WATCH_BGPID"; RC=$?
WATCH_BGPID=""
ELAPSED=$((SECONDS - T0))
expect "docker stop (SIGQUIT): shutdown limpio, exit 131" "131" "$RC"
expect "docker stop no agota el timeout de gracia (shutdown < 8s, no SIGKILL a los 10s)" "ok" "$([[ $ELAPSED -lt 8 ]] && echo ok || echo no)"
expect "sin contenedor de watch residual tras docker stop" "0" "$(docker ps -a --filter "name=$CNAME" --format '{{.Names}}' | wc -l)"

echo "== F. theme_build sigue siendo independiente tras cerrar el experimento =="
cp "$TMP/_foundation-marker.scss.orig" "$PDIR/scss/_foundation-marker.scss"
cp "$TMP/main.js.orig" "$PDIR/js/main.js"
RC=0
"${COMPOSE[@]}" run --rm -T theme_build >"$TMP/build-restore.log" 2>&1 || RC=$?
expect "theme_build tras restaurar el source: exit 0" "0" "$RC"
[[ "$RC" == 0 ]] && DIRTY=0
expect "el CSS vuelve al estado del source (sin los marcadores de prueba)" "0" "$(curl -sS "$URL$(css_ref)" | grep -c -- "--theme-watch-test" || true)"
expect "ownership del partial tras el build de restauración" "$(host_owner "$PDIR/templates")" "$(host_owner "$PARTIAL")"
expect "git: sin cambios inesperados en el plugin" "" "$(git status --porcelain -uall -- "$PDIR" | grep -E '_layout_start\.php$' || true)"

echo
if ((fails)); then echo "test-theme-watch: $fails fallo(s)"; exit 1; fi
echo "test-theme-watch: OK"
