#!/usr/bin/env bash
# Prueba de integración del ciclo de desarrollo del theme arUnicaucaB5Plugin (Docker Compose DEV).
#
#   plugins/arUnicaucaB5Plugin/tests/test-theme.sh
#
# Cubre: bind selectivo del plugin (atom/worker RO, nginx solo images/), theme_build (exit, partial generado,
# ownership, coherencia con theme_dist), Nginx (bundle custom, images, sin exponer source), opcache en atom,
# runtime READY, y los flujos de edición (template PHP sin reload, SCSS → theme build, image estática, build fallido).
#
# Opera sobre el proyecto Compose seleccionado por el entorno estándar (COMPOSE_FILE / COMPOSE_PROJECT_NAME /
# ATOM_WEB_PORT); por defecto la DEV real. No destruye estado (nunca `down -v`). Cambios temporales que hace y revierte
# (trap): ediciones de ficheros del plugin, un fichero de prueba en images/, y la activación TEMPORAL/MANUAL del theme en
# la BD (`tools:atom-plugins add`) si no estaba activo; termina reconstruyendo el theme desde el source restaurado.
# Requiere el runtime arriba (`docker compose up -d --wait`).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
COMPOSE=(docker compose)
PLUGIN=arUnicaucaB5Plugin
PDIR="plugins/$PLUGIN"
PIN=/atom/src/plugins/$PLUGIN
PORT="${ATOM_WEB_PORT:-8080}"
URL="${ATOM_WEB_URL:-http://127.0.0.1:$PORT}"
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
TMP="$(mktemp -d)"
PARTIAL="$PDIR/templates/_layout_start.php"
fails=0
ACTIVATED=0
FOOTER_CREATED=0
OVERRIDE="$PDIR/templates/_footer.php" # override temporal (partial de la app) que crea esta prueba y borra al terminar

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:90}"; else echo "FAIL  $1 -> ${3:0:300} (esperado ${2:0:300})"; fails=$((fails + 1)); fi
}
expect_ne() { # <descripción> <no esperado> <resultado>
  if [[ "$3" != "$2" ]]; then echo "PASS  $1 -> ${3:0:90}"; else echo "FAIL  $1 -> ${3:0:300} (no debía ser ${2:0:300})"; fails=$((fails + 1)); fi
}

svc_id() { "${COMPOSE[@]}" ps -aq "$1"; }
in_svc() { local s="$1"; shift; "${COMPOSE[@]}" exec -T "$s" "$@"; }
code() { curl -sS --max-time 20 -o /dev/null -w '%{http_code}' "$@"; }
mounts() { # <servicio> → "tipo destino modo;" ordenados, solo mounts relacionados con el theme
  docker inspect -f '{{range .Mounts}}{{.Type}} {{.Destination}} {{if .RW}}rw{{else}}ro{{end}};{{end}}' "$(svc_id "$1")" \
    | tr ';' '\n' | grep -E "$PLUGIN|/atom/src/dist" | sort | tr '\n' ';' | sed 's/;$//'
}
run_build() { # <log> → exit del build
  local rc=0
  "${COMPOSE[@]}" run --rm -T theme_build >"$1" 2>&1 || rc=$?
  echo "$rc"
}
host_owner() { stat -c '%u:%g' "$1"; }
refs_of_partial() { grep -oE '/dist/[^"'\''<> ]+\.(js|css)' "$PARTIAL" | sort -u; }
css_ref() { refs_of_partial | grep -E "/css/$PLUGIN\.bundle\." | awk 'NR==1'; }
js_ref() { refs_of_partial | grep -E "/js/$PLUGIN\.bundle\." | awk 'NR==1'; }

cleanup() {
  local rc=$?
  trap - EXIT INT TERM
  set +e
  for f in scss/main.scss scss/_foundation-marker.scss; do
    [[ -f "$TMP/$(basename "$f").orig" ]] && cp "$TMP/$(basename "$f").orig" "$PDIR/$f"
  done
  rm -f "$PDIR/images/theme-test-$RUN.txt"
  ((FOOTER_CREATED)) && rm -f "$OVERRIDE"
  ((ACTIVATED)) && in_svc atom php symfony tools:atom-plugins delete "$PLUGIN" >/dev/null 2>&1
  # Deja el partial y dist coherentes con el source restaurado.
  if [[ -f "$TMP/dirty" ]]; then "${COMPOSE[@]}" run --rm -T theme_build >"$TMP/final-build.log" 2>&1 || { echo "WARN  el build final de restauración falló:"; tail -n 20 "$TMP/final-build.log"; }; fi
  rm -rf "$TMP"
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

backup() { cp "$PDIR/$1" "$TMP/$(basename "$1").orig"; touch "$TMP/dirty"; }

echo "== A. Definición Compose (bind selectivo, theme_dist, dependencias) =="
# El JSON de `docker compose config` se analiza con el PHP de la imagen AtoM (sin parser en el host): un `run` de un solo
# uso (--no-deps, entrypoint php) que lee el JSON por stdin y devuelve una línea `clave=valor` por hecho.
read -r -d '' FACTS_PHP <<'PHP' || true
$c = json_decode(stream_get_contents(STDIN), true)['services'];
$p = $argv[1];
$m = fn($v) => empty($v['read_only']) ? 'rw' : 'ro';
$vols = function ($s, $keep) use ($c, $m) {
    $o = [];
    foreach ($c[$s]['volumes'] ?? [] as $v) { if ($keep($v)) { $o[] = $v['target'].' '.$m($v); } }
    sort($o);
    return implode(';', $o);
};
$dev = $needs = [];
foreach ($c as $n => $d) {
    if (isset(($d['environment'] ?? [])['ATOM_DEVELOPMENT_MODE'])) { $dev[] = $n; }
    if (($d['depends_on']['theme_build']['condition'] ?? '') === 'service_completed_successfully') { $needs[] = $n; }
}
sort($dev); sort($needs);
$out = [
    'image_atom' => $c['atom']['image'],
    'image_theme_build' => $c['theme_build']['image'],
    'theme_build_vols' => $vols('theme_build', fn($v) => true),
    'atom_binds' => $vols('atom', fn($v) => $v['type'] === 'bind'),
    'worker_plugin_binds' => $vols('atom_worker', fn($v) => $v['type'] === 'bind' && strpos($v['target'], $p) !== false),
    'worker_dist_mounts' => count(array_filter($c['atom_worker']['volumes'], fn($v) => $v['target'] === '/atom/src/dist')),
    'nginx_theme_vols' => $vols('nginx', fn($v) => $v['target'] === '/atom/src/dist' || strpos($v['target'], $p) !== false),
    'dev_mode_services' => implode(',', $dev),
    'wait_theme_build' => implode(',', $needs),
    'bootstrap_needs_theme_build' => (int) isset($c['bootstrap']['depends_on']['theme_build']),
    'theme_build_deps' => count($c['theme_build']['depends_on'] ?? []),
    'nginx_host_ip' => $c['nginx']['ports'][0]['host_ip'] ?? '',
];
foreach ($out as $k => $v) { echo $k, '=', $v, "\n"; }
PHP
FACTS="$("${COMPOSE[@]}" config --format json | "${COMPOSE[@]}" run --rm -T --no-deps --entrypoint php theme_build -r "$FACTS_PHP" -- "$PLUGIN" 2>/dev/null)"
fact() { grep "^$1=" <<<"$FACTS" | cut -d= -f2- || true; }
expect "el análisis de la configuración Compose produjo resultados" "12" "$(grep -c '=' <<<"$FACTS" || true)"
expect "theme_build usa la misma imagen que atom" "$(fact image_atom)" "$(fact image_theme_build)"
expect "theme_build: plugin RW + theme_dist RW en /atom/src/dist" "/atom/src/dist rw;$PIN rw" "$(fact theme_build_vols)"
expect "atom: solo el plugin, RO (sin theme_dist)" "$PIN ro" "$(fact atom_binds)"
expect "atom_worker: plugin RO" "$PIN ro" "$(fact worker_plugin_binds)"
expect "atom_worker no monta theme_dist" "0" "$(fact worker_dist_mounts)"
expect "nginx: theme_dist RO + solo images/ del plugin RO" "/atom/src/dist ro;$PIN/images ro" "$(fact nginx_theme_vols)"
expect "ATOM_DEVELOPMENT_MODE solo en atom" "atom" "$(fact dev_mode_services)"
expect "atom, atom_worker y nginx esperan a theme_build (completed_successfully)" "atom,atom_worker,nginx" "$(fact wait_theme_build)"
expect "bootstrap NO depende de theme_build" "0" "$(fact bootstrap_needs_theme_build)"
expect "theme_build no depende de nada (sin BD)" "0" "$(fact theme_build_deps)"
expect "el puerto web sigue solo en 127.0.0.1" "127.0.0.1" "$(fact nginx_host_ip)"

echo "== B. Source del plugin (project-owned) e higiene Git =="
expect "el source del plugin vive en el repo (config, webpack.entry, scss, js, images)" "ok" \
  "$([[ -f $PDIR/config/${PLUGIN}Configuration.class.php && -f $PDIR/webpack.entry.js && -f $PDIR/scss/main.scss && -f $PDIR/js/main.js && -d $PDIR/images ]] && echo ok || echo no)"
expect "la clase Configuration usa el nombre nuevo y extiende arDominionB5PluginConfiguration (skeleton oficial)" "1" "$(grep -c "class ${PLUGIN}Configuration extends arDominionB5PluginConfiguration" "$PDIR/config/${PLUGIN}Configuration.class.php")"
expect "la Configuration hereda la precedencia del skeleton: parent::initialize() y sin array_unshift propio" "1:0" \
  "$(grep -c 'parent::initialize()' "$PDIR/config/${PLUGIN}Configuration.class.php"):$(grep -c 'array_unshift' "$PDIR/config/${PLUGIN}Configuration.class.php" || true)"
expect "el summary contiene 'theme' (AtoM lo lista como tema)" "1" "$(grep -cEi "summary = '.*theme" "$PDIR/config/${PLUGIN}Configuration.class.php")"
expect "scss/ contiene EXACTAMENTE main.scss y _foundation-marker.scss (sin parciales copiados de Dominion)" "./_foundation-marker.scss ./main.scss" \
  "$(cd "$PDIR/scss" && find . -type f | sort | tr '\n' ' ' | sed 's/ $//')"
expect "sin layout.php ni logos duplicados de Dominion" "0" \
  "$(ls "$PDIR/templates/layout.php" "$PDIR/images/logo.png" "$PDIR/images/default_atom_logo.png" 2>/dev/null | wc -l)"
expect "_layout_start.php está ignorado por Git" "ignored" "$(git check-ignore -q "$PARTIAL" && echo ignored || echo tracked)"
expect "ningún generated output (partial, dist) es trackeable" "0" \
  "$(git ls-files -o --exclude-standard -- "$PDIR" | grep -cE '_layout_start\.php$|(^|/)dist/|node_modules' || true)"
expect "upstream/atom intacto" "" "$(git -C upstream/atom status --porcelain)"

echo "== C. theme_build =="
RC="$(run_build "$TMP/build1.log")"
expect "theme_build termina con exit 0" "0" "$RC"
[[ "$RC" == 0 ]] || tail -n 30 "$TMP/build1.log"
expect "se generó templates/_layout_start.php" "yes" "$([[ -s $PARTIAL ]] && echo yes || echo no)"
expect "ownership del partial = el del directorio host que lo contiene" "$(host_owner "$PDIR/templates")" "$(host_owner "$PARTIAL")"
expect "el wrapper reportó la coherencia partial <-> dist" "1" "$(grep -c 'theme-build: OK: .* bundles referenciados' "$TMP/build1.log" || true)"
expect "el partial referencia los bundles custom (js + css)" "2" "$(refs_of_partial | grep -cE "/$PLUGIN\.bundle\." || true)"
MISSING=0
for r in $(refs_of_partial); do in_svc nginx test -s "/atom/src$r" || { echo "  falta en nginx: $r"; MISSING=$((MISSING + 1)); }; done
expect "todo bundle referenciado existe en theme_dist (visto desde nginx)" "0" "$MISSING"
expect "los ficheros source no se ven en ningún dist (solo bundles)" "0" "$(in_svc nginx sh -c 'find /atom/src/dist -name "*.scss" | wc -l')"
expect "git status limpio de derivados tras el build" "" "$(git status --porcelain -uall -- "$PDIR" | grep -E '_layout_start\.php$|dist/' || true)"

echo "== D. Runtime: atom, worker, nginx =="
"${COMPOSE[@]}" up -d --wait >/dev/null 2>&1
expect "web READY (200 + marcador AtoM)" "READY" "$(scripts/web-ready.sh >/dev/null 2>&1 && echo READY || echo NOT_READY)"
expect "atom ve el plugin (Configuration)" "ok" "$(in_svc atom test -f "$PIN/config/${PLUGIN}Configuration.class.php" && echo ok || echo no)"
expect "worker ve el plugin (Configuration)" "ok" "$(in_svc atom_worker test -f "$PIN/config/${PLUGIN}Configuration.class.php" && echo ok || echo no)"
expect "atom: mount del plugin RO" "bind $PIN ro" "$(mounts atom)"
expect "atom_worker: mount del plugin RO" "bind $PIN ro" "$(mounts atom_worker)"
expect "nginx: theme_dist RO + solo images/ del plugin RO" "bind $PIN/images ro;volume /atom/src/dist ro" "$(mounts nginx)"
expect "atom no puede escribir en el plugin (RO)" "ro" "$(in_svc atom sh -c "touch $PIN/.w 2>/dev/null && echo rw || echo ro")"
expect "worker no puede escribir en el plugin (RO)" "ro" "$(in_svc atom_worker sh -c "touch $PIN/.w 2>/dev/null && echo rw || echo ro")"
expect "nginx no puede escribir en dist (RO)" "ro" "$(in_svc nginx sh -c 'touch /atom/src/dist/.w 2>/dev/null && echo rw || echo ro')"
expect "atom: opcache.validate_timestamps = On" "On" "$(in_svc atom php -i | awk '/^opcache.validate_timestamps/ { print $3 }')"
expect "atom: opcache.revalidate_freq = 2 (hasta ~2 s de latencia)" "2" "$(in_svc atom php -i | awk '/^opcache.revalidate_freq/ { print $3 }')"
expect "worker: opcache.validate_timestamps sigue Off" "Off" "$(in_svc atom_worker php -i | awk '/^opcache.validate_timestamps/ { print $3 }')"
expect "ATOM_DEVELOPMENT_MODE no está en el worker" "" "$(in_svc atom_worker sh -c 'printenv ATOM_DEVELOPMENT_MODE || true')"
expect "el puerto web se publica solo en loopback" "127.0.0.1:$PORT" "$(docker port "$(svc_id nginx)" 80/tcp | head -n1)"

echo "== E. Nginx: bundles, images y NO exposición del source =="
CSS="$(css_ref)"
expect "Nginx sirve el CSS custom (200)" "200" "$(code "$URL$CSS")"
expect "Nginx sirve el JS custom (200)" "200" "$(code "$URL$(js_ref)")"
expect "Nginx sirve images/image.png del plugin (200, idéntico al source)" "identical" \
  "$(curl -sS "$URL/plugins/$PLUGIN/images/image.png" | cmp -s - "$PDIR/images/image.png" && echo identical || echo different)"
for f in scss/main.scss scss/_foundation-marker.scss webpack.entry.js js/main.js templates/_layout_start_webpack.php \
         config/${PLUGIN}Configuration.class.php tools/build.sh tests/test-theme.sh; do
  expect_ne "HTTP no expone $f del plugin" "200" "$(code "$URL/plugins/$PLUGIN/$f")"
done
expect "el body de scss/main.scss no es el source" "0" "$(curl -sS "$URL/plugins/$PLUGIN/scss/main.scss" | grep -c '@import' || true)"
expect "Nginx no contiene scss/, templates/ ni webpack.entry.js del plugin" "images" "$(in_svc nginx ls "$PIN")"

echo "== F. Activación TEMPORAL/MANUAL del theme + edición de PHP/templates sin reload =="
# Activación manual (hasta el reconcile de WU-13): `tools:atom-plugins add`, revertida por el trap si la hizo esta prueba.
if ! in_svc atom php symfony tools:atom-plugins list | grep -qx "$PLUGIN"; then
  in_svc atom php symfony tools:atom-plugins add "$PLUGIN" >/dev/null && ACTIVATED=1
fi
expect "el plugin figura habilitado en la BD" "1" "$(in_svc atom php symfony tools:atom-plugins list | grep -cx "$PLUGIN")"
page() { curl -sS --max-time 20 "$URL${1:-/}"; }
poll_for() { # <patrón> <segundos> [ruta] → found|missing (espera a que aparezca en la página)
  local end=$((SECONDS + $2)) h
  while ((SECONDS < end)); do h="$(page "${3:-/}")"; grep -q -- "$1" <<<"$h" && { echo found; return; }; sleep 1; done
  echo missing
}
poll_gone() { # <patrón> <segundos> [ruta] → gone|still (espera a que desaparezca de la página)
  local end=$((SECONDS + $2)) h
  while ((SECONDS < end)); do h="$(page "${3:-/}")"; grep -q -- "$1" <<<"$h" || { echo gone; return; }; sleep 1; done
  echo still
}
# Dominion sigue habilitado en la BD: la precedencia la da la propia Configuration del skeleton (extiende Dominion y antepone
# sus templates). Habilitar/deshabilitar themes en la BD es cuestión del reconcile (WU-13), no de esta prueba.
expect "el theme custom gana sobre arDominionB5Plugin (meta atom-theme)" "found" "$(poll_for 'name="atom-theme" content="arUnicaucaB5Plugin"' 10)"
expect "la página referencia los bundles custom" "1" "$(page | grep -c "/dist/css/$PLUGIN\.bundle\." || true)"
expect "el CSS referenciado por la página lo sirve Nginx" "200" "$(code "$URL$(page | grep -oE "/dist/css/$PLUGIN\.bundle\.[0-9a-f]+\.css" | head -n1)")"
ATOM_BEFORE="$(svc_id atom)"; ATOM_START="$(docker inspect -f '{{.State.StartedAt}}' "$ATOM_BEFORE")"
ATOM_IMG="$(docker inspect -f '{{.Image}}' "$ATOM_BEFORE")"
NGINX_START="$(docker inspect -f '{{.State.StartedAt}}' "$(svc_id nginx)")"
# Override PHP mínimo: un partial de la app (`_footer.php`) copiado al plugin, como indica el skeleton. Lo crea y lo borra esta prueba.
[[ ! -e "$OVERRIDE" ]] || { echo "STOP  $OVERRIDE ya existe (override durable): la prueba no lo sobrescribe" >&2; exit 2; }
FOOTER_CREATED=1
echo "<!-- theme-test-$RUN-v1 -->" >"$OVERRIDE"
expect "override nuevo templates/_footer.php → visible tras refresh (≤10 s, sin reload de FPM)" "found" "$(poll_for "theme-test-$RUN-v1" 10)"
echo "<!-- theme-test-$RUN-v2 -->" >"$OVERRIDE"
expect "editar el override → la versión nueva se ve tras refresh (≤10 s)" "found" "$(poll_for "theme-test-$RUN-v2" 10)"
rm -f "$OVERRIDE"
expect "borrar el override → el marcador desaparece" "gone" "$(poll_gone "theme-test-$RUN-v2" 10)"
expect "sin recrear atom ni rebuild de imagen" "$ATOM_START $ATOM_IMG" "$(docker inspect -f '{{.State.StartedAt}}' "$(svc_id atom)") $(docker inspect -f '{{.Image}}' "$(svc_id atom)")"

echo "== G. Estático directo (images/) sin rebuild ni recrear nginx =="
echo "v1-$RUN" >"$PDIR/images/theme-test-$RUN.txt"
expect "fichero nuevo bajo images/ → servido" "v1-$RUN" "$(curl -sS "$URL/plugins/$PLUGIN/images/theme-test-$RUN.txt")"
echo "v2-$RUN" >"$PDIR/images/theme-test-$RUN.txt"
expect "editado → Nginx sirve la versión actualizada" "v2-$RUN" "$(curl -sS "$URL/plugins/$PLUGIN/images/theme-test-$RUN.txt")"
rm -f "$PDIR/images/theme-test-$RUN.txt"
expect "borrado → ya no se sirve" "404" "$(code "$URL/plugins/$PLUGIN/images/theme-test-$RUN.txt")"
expect "nginx no se recreó ni reinició" "$NGINX_START" "$(docker inspect -f '{{.State.StartedAt}}' "$(svc_id nginx)")"

echo "== H. Edición de SCSS → theme build → refresh (sin rebuild de imagen) =="
OLD_CSS="$(css_ref)"
backup scss/_foundation-marker.scss
printf '\nbody { --theme-test: "%s"; }\n' "$RUN" >>"$PDIR/scss/_foundation-marker.scss"
expect "sin build, dist sigue con el bundle anterior" "$OLD_CSS" "$(css_ref)"
RC="$(run_build "$TMP/build2.log")"
expect "theme_build tras editar SCSS: exit 0" "0" "$RC"
NEW_CSS="$(css_ref)"
expect_ne "el partial referencia un bundle CSS nuevo (hash distinto)" "$OLD_CSS" "$NEW_CSS"
expect "Nginx sirve el bundle nuevo" "200" "$(code "$URL$NEW_CSS")"
expect "el bundle nuevo contiene la edición" "1" "$(curl -sS "$URL$NEW_CSS" | grep -c -- "--theme-test: \"$RUN\"" || true)"
expect "dist reconciliado: el bundle anterior ya no existe (clean: true)" "404" "$(code "$URL$OLD_CSS")"
expect "el refresh de la página apunta al bundle nuevo" "$NEW_CSS" "$(page | grep -oE "/dist/css/$PLUGIN\.bundle\.[0-9a-f]+\.css" | head -n1)"
expect "la imagen de atom no cambió (sin rebuild de imagen)" "$ATOM_IMG" "$(docker inspect -f '{{.Image}}' "$(svc_id atom)")"
expect "ownership del partial tras el rebuild" "$(host_owner "$PDIR/templates")" "$(host_owner "$PARTIAL")"

echo "== I. Build fallido: exit != 0 y ownership corregido igualmente =="
backup scss/main.scss
printf '@import "theme-test-inexistente-%s";\n' "$RUN" >>"$PDIR/scss/main.scss"
# El contenedor (root) deja el partial como root:root para demostrar que el trap lo corrige aunque el build falle.
"${COMPOSE[@]}" run --rm -T --no-deps --entrypoint chown theme_build 0:0 "$PIN/templates/_layout_start.php"
expect "precondición: el partial pasó a ser de root" "0:0" "$(host_owner "$PARTIAL")"
RC="$(run_build "$TMP/build3.log")"
expect_ne "theme_build con SCSS roto propaga exit != 0" "0" "$RC"
expect "el error del build se reporta" "1" "$(grep -c 'theme-build: ERROR: el build falló' "$TMP/build3.log" || true)"
expect "ownership corregido pese al fallo del build (trap)" "$(host_owner "$PDIR/templates")" "$(host_owner "$PARTIAL")"
cp "$TMP/main.scss.orig" "$PDIR/scss/main.scss"

echo "== J. Restauración =="
cp "$TMP/_foundation-marker.scss.orig" "$PDIR/scss/_foundation-marker.scss"
RC="$(run_build "$TMP/build4.log")"
expect "theme_build con el source restaurado: exit 0" "0" "$RC"
[[ "$RC" == 0 ]] && rm -f "$TMP/dirty"
expect "el CSS vuelve al estado del source (sin la edición de prueba)" "0" "$(curl -sS "$URL$(css_ref)" | grep -c -- "--theme-test" || true)"
expect "web READY al terminar" "READY" "$(scripts/web-ready.sh >/dev/null 2>&1 && echo READY || echo NOT_READY)"
expect "git: sin cambios inesperados en el plugin" "" "$(git status --porcelain -uall -- "$PDIR" | grep -E '_layout_start\.php$|images/theme-test' || true)"

echo
if ((fails)); then echo "test-theme: $fails fallo(s)"; exit 1; fi
echo "test-theme: OK"
