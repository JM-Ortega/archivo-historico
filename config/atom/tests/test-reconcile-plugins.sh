#!/usr/bin/env bash
# Prueba de integración del reconcile de plugins de AtoM (config/atom/reconcile-plugins.sh) contra el Compose DEV.
#
#   config/atom/tests/test-reconcile-plugins.sh
#
# Aislamiento: los casos que necesitan alterar el estado de plugins (ausente, ajenos, entradas inválidas, costuras que
# simulan una CLI defectuosa) corren sobre una BD SCRATCH clonada de `atom` (nombre con id aleatorio, usuario propio con
# permisos solo sobre ella), que se elimina al terminar. La observación es independiente del script bajo prueba: se lee
# el valor serializado del setting `plugins` directamente de la BD, no con `tools:atom-plugins list`.
# La BD `atom` real solo se toca en la sección final (gate y happy path con `up`, que es el uso normal) y nunca se destruye
# estado (nunca `down -v`). Requiere el runtime arriba (`docker compose up -d --wait`).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
COMPOSE=(docker compose)
ROOT_PW="${MYSQL_ROOT_PASSWORD:-my-secret-pw}"
THEME=arUnicaucaB5Plugin
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
SCRATCH="rc$RUN" # sin "_": en GRANT, "_" de un nombre de base es un comodín
USR="ru$RUN"
PW="pw$RUN"
TMP="$(mktemp -d)"
fails=0
OUT=
RC=0
CREATED_DB=0
CREATED_USER=0

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:100}"; else echo "FAIL  $1 -> ${3:0:600} (esperado ${2:0:600})"; fails=$((fails + 1)); fi
}
expect_ne() { # <descripción> <no esperado> <resultado>
  if [[ "$3" != "$2" ]]; then echo "PASS  $1 -> ${3:0:100}"; else echo "FAIL  $1 -> ${3:0:600} (no debía ser ${2:0:600})"; fails=$((fails + 1)); fi
}
expect_out() { # <descripción> <regex que debe aparecer en $OUT>
  if grep -Eq "$2" <<<"$OUT"; then echo "PASS  $1"; else echo "FAIL  $1 (no aparece /$2/ en: ${OUT:0:500})"; fails=$((fails + 1)); fi
}

admin() { "${COMPOSE[@]}" exec -T percona mysql -uroot -p"$ROOT_PW" --batch --skip-column-names "$@" 2>/dev/null; }
cleanup() {
  ((CREATED_DB)) && admin -e "DROP DATABASE \`$SCRATCH\`" || true
  ((CREATED_USER)) && admin -e "DROP USER '$USR'@'%'" || true
  rm -rf "$TMP"
}
trap cleanup EXIT

# --- observación independiente (SQL sobre el valor serializado del setting `plugins`) ---
plugins_sql() { # <db> → un plugin por línea, en el orden persistido
  admin "$1" -e "SELECT i.value FROM setting s JOIN setting_i18n i ON i.id = s.id AND i.culture = s.source_culture WHERE s.name = 'plugins'" \
    | grep -oE '"[A-Za-z0-9_]+"' | tr -d '"'
}
line() { plugins_sql "$1" | tr '\n' ' ' | sed 's/ $//'; } # una línea, para comparar
fp() { admin "$1" -e "CHECKSUM TABLE setting, setting_i18n" | tr -s '\t' ' ' | tr '\n' ';'; } # huella de las tablas de settings
set_plugins() { # <nombres...> → fija el valor serializado (estado controlado de prueba)
  local n=$# i=0 s p
  s="a:$n:{"
  for p in "$@"; do s+="i:$i;s:${#p}:\"$p\";"; i=$((i + 1)); done
  s+="}"
  admin "$SCRATCH" -e "UPDATE setting_i18n i JOIN setting s ON i.id = s.id AND i.culture = s.source_culture SET i.value = '$s' WHERE s.name = 'plugins'"
}

# reconcile [-e VAR=valor | -v mount ...] [-- comando...] → $OUT y $RC (contra la BD scratch)
reconcile() {
  local opts=() cmd=()
  while (($#)); do
    if [[ $1 == -- ]]; then shift; cmd=("$@"); break; fi
    opts+=("$1"); shift
  done
  RC=0
  OUT=$("${COMPOSE[@]}" run --rm --no-deps -T \
    -e ATOM_MYSQL_DSN="mysql:host=percona;port=3306;dbname=$SCRATCH;charset=utf8mb4" \
    -e ATOM_MYSQL_USERNAME="$USR" -e ATOM_MYSQL_PASSWORD="$PW" \
    ${opts[@]+"${opts[@]}"} reconcile ${cmd[@]+"${cmd[@]}"} 2>&1) || RC=$?
}
# Carga el script como librería y ejecuta un fragmento (redefine las costuras de la CLI) antes de main.
reconcile_seam() { reconcile -- bash -c "source /project/scripts/reconcile-plugins.sh; $1; main"; }
# Con un .conf alternativo (montado en /conf).
with_conf() { # <contenido> [opciones de run...]
  local content=$1; shift
  printf '%b' "$content" >"$TMP/x.conf"
  reconcile -v "$TMP:/conf:ro" -e RECONCILE_REQUIRED_PLUGINS=/conf/x.conf "$@"
}

echo "== preparación: BD scratch clonada de atom =="
"${COMPOSE[@]}" up -d --wait percona >/dev/null
DEV_FP_BEFORE="$(fp atom)"
admin -e "CREATE DATABASE \`$SCRATCH\`"; CREATED_DB=1
admin -e "CREATE USER '$USR'@'%' IDENTIFIED BY '$PW'"; CREATED_USER=1
admin -e "GRANT ALL PRIVILEGES ON \`$SCRATCH\`.* TO '$USR'@'%'"
"${COMPOSE[@]}" exec -T percona sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" atom 2>/dev/null | mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" $SCRATCH 2>/dev/null"
BASE=(sfDcPlugin arDominionB5Plugin sfEacPlugin sfEadPlugin sfIsaarPlugin sfIsadPlugin arDacsPlugin sfIsdfPlugin sfIsdiahPlugin sfModsPlugin sfRadPlugin sfSkosPlugin)
set_plugins "${BASE[@]}"
expect "scratch: estado controlado sin el theme" "${BASE[*]}" "$(line "$SCRATCH")"

echo "== Contrato estático (compose) =="
CFG="$("${COMPOSE[@]}" config --format json)"
py() { python3 -c "import json,sys; d=json.load(sys.stdin)['services']; $1" <<<"$CFG"; }
expect "el desired state es un fichero versionado con el plugin requerido" "$THEME" "$(grep -vE '^\s*(#|$)' config/atom/required-plugins.conf)"
expect "reconcile depende del bootstrap (completed_successfully)" "service_completed_successfully" "$(py "print(d['reconcile']['depends_on']['bootstrap']['condition'])")"
expect "reconcile NO depende de theme_build (gates independientes)" "bootstrap" "$(py "print(' '.join(sorted(d['reconcile']['depends_on'])))")"
expect "atom depende del reconcile (completed_successfully)" "service_completed_successfully" "$(py "print(d['atom']['depends_on']['reconcile']['condition'])")"
expect "atom_worker depende del reconcile (completed_successfully)" "service_completed_successfully" "$(py "print(d['atom_worker']['depends_on']['reconcile']['condition'])")"
expect "reconcile: solo binds RO (plugin, script, desired state), sin puertos" "ro ro ro none" \
  "$(py "r=d['reconcile']; print(' '.join(v.get('read_only') and 'ro' or 'RW' for v in r['volumes']), 'none' if 'ports' not in r else 'PORTS')")"
expect "reconcile conserva el entrypoint upstream (no lo redefine)" "null" "$(py "print(json.dumps(d['reconcile'].get('entrypoint')))")"

echo "== A. Plugin requerido ausente → habilitado (y postcondición releída) =="
reconcile
expect "exit 0" "0" "$RC"
expect_out "verificó la postcondición" "habilitado y verificado"
expect "BD: el plugin quedó habilitado y el resto intacto (añadido al final)" "${BASE[*]} $THEME" "$(line "$SCRATCH")"

echo "== B. Idempotencia =="
FP1="$(fp "$SCRATCH")"
reconcile
expect "exit 0" "0" "$RC"
expect_out "no escribe: ya habilitado" "ya habilitado; sin cambios"
expect "tablas de settings idénticas (sin escritura)" "$FP1" "$(fp "$SCRATCH")"
expect "lista de plugins idéntica" "${BASE[*]} $THEME" "$(line "$SCRATCH")"
reconcile
expect "tercera ejecución: exit 0" "0" "$RC"
expect "tercera ejecución: tablas idénticas" "$FP1" "$(fp "$SCRATCH")"

echo "== C. Preservación de plugins ajenos =="
# Ajenos: unos reales habilitados, otros reales DESHABILITADOS (no deben re-añadirse) y uno inexistente en el filesystem.
set_plugins sfDcPlugin sfEacPlugin arAjenoInexistentePlugin
reconcile
expect "exit 0 (un ajeno inexistente en la BD no bloquea)" "0" "$RC"
expect "los ajenos permanecen y no se añade ninguno más que el requerido" "sfDcPlugin sfEacPlugin arAjenoInexistentePlugin $THEME" "$(line "$SCRATCH")"
expect "arDominionB5Plugin (deshabilitado aquí) no se gestiona: no reaparece" "0" "$(plugins_sql "$SCRATCH" | grep -cx arDominionB5Plugin || true)"
set_plugins "$THEME" sfDcPlugin
FP2="$(fp "$SCRATCH")"
reconcile
expect "requerido ya presente + ajenos ausentes: no-op (no repone plugins ajenos)" "$THEME sfDcPlugin" "$(line "$SCRATCH")"
expect "no-op: tablas de settings idénticas" "$FP2" "$(fp "$SCRATCH")"
expect "el código del reconcile no invoca ninguna acción de borrado (delete)" "0" "$(grep -vE '^[[:space:]]*#' config/atom/reconcile-plugins.sh | grep -c 'delete' || true)"

echo "== D. Validación: entrada inválida / source inválido no producen falso éxito =="
set_plugins "${BASE[@]}"
FP3="$(fp "$SCRATCH")"
for bad in '../../etc/passwd' 'arFoo Plugin' 'arFoo;touchPlugin' 'arFooplugin' '/atom/src/plugins/arFooPlugin' '$(id)Plugin'; do
  with_conf "$bad\n"
  expect "entrada inválida '$bad' → exit 64" "64" "$RC"
done
with_conf "# solo comentarios\n\n"; expect "conf sin plugins → exit 64" "64" "$RC"
with_conf ""; expect "conf vacío → exit 64" "64" "$RC"
reconcile -e RECONCILE_REQUIRED_PLUGINS=/conf/no-existe.conf; expect "conf inexistente → exit 64" "64" "$RC"
with_conf "$THEME\nnombre inválido\n"; expect "un plugin válido + una línea inválida → exit 64 (todo o nada)" "64" "$RC"
expect "ninguna entrada inválida escribió en la BD" "$FP3" "$(fp "$SCRATCH")"

with_conf "arNoExistePlugin\n"
expect "plugin sin source (nombre válido) → exit 65" "65" "$RC"
expect_out "el motivo es el source" "no existe /atom/src/plugins/arNoExistePlugin"
expect "no se escribió nada (la CLI habría aceptado el nombre)" "$FP3" "$(fp "$SCRATCH")"
# Control: SIN el reconcile, la CLI de AtoM acepta el mismo nombre inexistente con exit 0 (por eso el exit 0 no basta).
reconcile -- bash -c 'cd /atom/src && php symfony tools:atom-plugins add arNoExistePlugin >/dev/null'
expect "control: tools:atom-plugins add de un plugin inexistente sale con 0" "0" "$RC"
expect "control: y lo persiste" "1" "$(plugins_sql "$SCRATCH" | grep -cx arNoExistePlugin || true)"
set_plugins "${BASE[@]}"
FP3="$(fp "$SCRATCH")"

mkdir -p "$TMP/plugins/arSinConfigPlugin/config" "$TMP/plugins/arRotoPlugin/config" "$TMP/plugins/arOtraClasePlugin/config" "$TMP/plugins/arSinDirPlugin"
printf '<?php\nclass arRotoPluginConfiguration extends {\n' >"$TMP/plugins/arRotoPlugin/config/arRotoPluginConfiguration.class.php"
printf '<?php\nclass arOtroNombreConfiguration {}\n' >"$TMP/plugins/arOtraClasePlugin/config/arOtraClasePluginConfiguration.class.php"
for p in arSinConfigPlugin arSinDirPlugin arRotoPlugin arOtraClasePlugin; do
  with_conf "$p\n" -v "$TMP/plugins/$p:/atom/src/plugins/$p:ro"
  expect "source inválido ($p) → exit 65" "65" "$RC"
done
with_conf "$THEME\narRotoPlugin\n" -v "$TMP/plugins/arRotoPlugin:/atom/src/plugins/arRotoPlugin:ro"
expect "válido + inválido → exit 65 sin escribir el válido (todo o nada)" "65" "$RC"
expect "ninguna validación fallida escribió en la BD" "$FP3" "$(fp "$SCRATCH")"
set_plugins "${BASE[@]}"
FP3="$(fp "$SCRATCH")"

echo "== E. Postcondición: nunca se confía en el exit 0 de la CLI =="
reconcile_seam 'atom_plugins_add() { return 0; }'
expect "add que sale con 0 sin añadir nada → exit 72" "72" "$RC"
expect_out "lo reporta como postcondición no satisfecha" "POSTCONDICIÓN NO SATISFECHA"
expect "la BD quedó como estaba" "$FP3" "$(fp "$SCRATCH")"
reconcile_seam 'atom_plugins_add() { (cd "$ATOM_SRC" && php symfony tools:atom-plugins add "$1" && php symfony tools:atom-plugins delete sfSkosPlugin) >/dev/null; }'
expect "add que además elimina un plugin ajeno → exit 72 (detectado por releer)" "72" "$RC"
expect_out "lo reporta" "cambió más allá de añadir"
set_plugins "${BASE[@]}"
FP3="$(fp "$SCRATCH")"
reconcile_seam 'atom_plugins_add() { return 3; }'
expect "add que falla → exit 71" "71" "$RC"
reconcile_seam 'atom_plugins_list() { return 1; }; atom_plugins_add() { echo LLAMADO >&2; }'
expect "no se puede observar → exit 70" "70" "$RC"
expect_ne "y no intentó escribir a ciegas" "1" "$(grep -c LLAMADO <<<"$OUT" || true)"
expect "las costuras no dejaron rastro no deseado: BD igual" "$FP3" "$(fp "$SCRATCH")"

expect "la BD real no cambió durante los casos scratch" "$DEV_FP_BEFORE" "$(fp atom)"

echo "== Gate y happy path sobre la DEV real =="
DEV_ATOM_BEFORE="$(fp atom)"
cat >"$TMP/bad-conf" <<<"arNoExistePlugin"
cat >"$TMP/gate.yaml" <<EOF
services:
  reconcile:
    volumes:
      - $TMP/bad-conf:/project/config/atom/required-plugins.conf:ro
EOF
GATE=("${COMPOSE[@]}" -f "$ROOT/compose.yaml" -f "$TMP/gate.yaml")
state_of() { local id; id="$("${COMPOSE[@]}" ps -aq "$1")"; if [[ -n "$id" ]]; then docker inspect -f '{{.State.Status}}' "$id"; else echo absent; fi; }
"${COMPOSE[@]}" rm -sf atom atom_worker >/dev/null 2>&1
GATE_RC=0
"${GATE[@]}" up -d --wait atom atom_worker >/dev/null 2>&1 || GATE_RC=$?
expect_ne "F. reconcile fallido: up falla" "0" "$GATE_RC"
expect "F. reconcile terminó con el exit 65 del source inválido" "65" "$(docker inspect -f '{{.State.ExitCode}}' "$("${COMPOSE[@]}" ps -aq reconcile)")"
expect_ne "F. atom no está running" "running" "$(state_of atom)"
expect_ne "F. atom_worker no está running" "running" "$(state_of atom_worker)"
expect "F. el reconcile fallido no alteró la BD real" "$DEV_ATOM_BEFORE" "$(fp atom)"

RECON_STARTED="$(docker inspect -f '{{.State.StartedAt}}' "$("${COMPOSE[@]}" ps -aq reconcile)")"
HAPPY_RC=0
"${COMPOSE[@]}" up -d --wait >"$TMP/up1.log" 2>&1 || HAPPY_RC=$?
expect "G. up -d --wait completa con éxito" "0" "$HAPPY_RC"
expect "G. reconcile terminó con 0" "0" "$(docker inspect -f '{{.State.ExitCode}}' "$("${COMPOSE[@]}" ps -aq reconcile)")"
expect_ne "G. reconcile se volvió a ejecutar en este up" "$RECON_STARTED" "$(docker inspect -f '{{.State.StartedAt}}' "$("${COMPOSE[@]}" ps -aq reconcile)")"
expect "G. el theme está habilitado en la BD real" "1" "$(plugins_sql atom | grep -cx "$THEME" || true)"
expect "G. el contenedor reconcile usa el entrypoint upstream" '["docker/entrypoint.sh"]' "$(docker inspect -f '{{json .Config.Entrypoint}}' "$("${COMPOSE[@]}" ps -aq reconcile)")"
for s in atom atom_worker nginx; do expect "G. $s healthy" "healthy" "$(docker inspect -f '{{.State.Health.Status}}' "$("${COMPOSE[@]}" ps -aq "$s")")"; done
expect "G. web READY" "READY" "$(scripts/web-ready.sh --wait 2>/dev/null | sed -E 's/^web-ready: (READY).*/\1/')"

FP_DEV1="$(fp atom)"
IDS1="$("${COMPOSE[@]}" ps -q atom atom_worker nginx | sort | tr '\n' ' ')"
RECON1="$(docker inspect -f '{{.State.StartedAt}}' "$("${COMPOSE[@]}" ps -aq reconcile)")"
AGAIN_RC=0
"${COMPOSE[@]}" up -d --wait >"$TMP/up2.log" 2>&1 || AGAIN_RC=$?
expect "H. segundo up -d --wait: exit 0" "0" "$AGAIN_RC"
expect_ne "H. reconcile se ejecutó de nuevo" "$RECON1" "$(docker inspect -f '{{.State.StartedAt}}' "$("${COMPOSE[@]}" ps -aq reconcile)")"
expect "H. reconcile: exit 0" "0" "$(docker inspect -f '{{.State.ExitCode}}' "$("${COMPOSE[@]}" ps -aq reconcile)")"
expect "H. reconcile no escribió (ya habilitado; sin cambios)" "1" "$(docker logs --since "$(docker inspect -f '{{.State.StartedAt}}' "$("${COMPOSE[@]}" ps -aq reconcile)")" "$("${COMPOSE[@]}" ps -aq reconcile)" 2>&1 | grep -c 'ya habilitado; sin cambios' || true)"
expect "H. tablas de settings de la BD real idénticas" "$FP_DEV1" "$(fp atom)"
expect "H. atom/atom_worker/nginx no se recrearon (sin churn)" "$IDS1" "$("${COMPOSE[@]}" ps -q atom atom_worker nginx | sort | tr '\n' ' ')"
expect "H. web READY" "READY" "$(scripts/web-ready.sh --wait 2>/dev/null | sed -E 's/^web-ready: (READY).*/\1/')"

echo
if ((fails)); then echo "test-reconcile-plugins: $fails fallo(s)"; exit 1; fi
echo "test-reconcile-plugins: OK"
