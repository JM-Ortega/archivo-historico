#!/usr/bin/env bash
# Prueba de integración del runtime `atom` del Compose DEV: gate de bootstrap, persistencia de
# uploads/ y downloads/ tras recrear el contenedor, y configuración runtime regenerada.
#
#   scripts/test-runtime.sh
#
# Usa el proyecto DEV real (base `atom`, volúmenes uploads_data/downloads_data) sin destruir estado:
#   - "recrear" = `rm -sf atom` + `up atom` (no se recrean percona/elasticsearch ni se ejecuta ningún reset);
#   - los artefactos de prueba (`integration-test-<id>`) se escriben y se borran solo en esta ejecución;
#   - el gate negativo apunta el bootstrap a una BD inexistente; no puede instalar (el usuario no tiene acceso).
# Si la BD `atom` está FRESH, el primer `up atom` la instala a través del bootstrap (uso normal del gate).
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose)
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
MARK="integration-test-$RUN"
UP=/atom/src/uploads
DOWN=/atom/src/downloads
fails=0

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:70}"; else echo "FAIL  $1 -> ${3:0:300} (esperado ${2:0:300})"; fails=$((fails + 1)); fi
}
expect_ne() { # <descripción> <no esperado> <resultado>
  if [[ "$3" != "$2" ]]; then echo "PASS  $1 -> ${3:0:70}"; else echo "FAIL  $1 -> ${3:0:300} (no debía ser ${2:0:300})"; fails=$((fails + 1)); fi
}

in_atom() { "${COMPOSE[@]}" exec -T atom "$@"; }
atom_id() { "${COMPOSE[@]}" ps -aq atom; }
atom_up() { "${COMPOSE[@]}" up -d --wait atom >/dev/null 2>&1; }
atom_status() { local id; id="$(atom_id)"; if [[ -n "$id" ]]; then docker inspect -f '{{.State.Status}}' "$id"; else echo absent; fi; }
atom_down() { "${COMPOSE[@]}" rm -sf atom >/dev/null 2>&1; }

# Ejecuta un PHP a través de la pool FPM real (cgi-fcgi contra 127.0.0.1:9000), no como el usuario de `exec`.
fpm_write() { # <ruta> <contenido> → "<euid> OK|FAIL"
  in_atom sh -c 'cat >/tmp/fpm-write.php' <<'PHP'
<?php echo posix_geteuid(), ' ', false !== file_put_contents($_GET['p'], $_GET['c']) ? 'OK' : 'FAIL';
PHP
  in_atom env SCRIPT_NAME=/fpm-write.php SCRIPT_FILENAME=/tmp/fpm-write.php REQUEST_METHOD=GET \
    QUERY_STRING="p=$1&c=$2" cgi-fcgi -bind -connect 127.0.0.1:9000 | tail -n1
}

cleanup() {
  if [[ -n "$(atom_id)" ]]; then
    in_atom rm -f "$UP/$MARK" "$UP/$MARK.fpm" "$DOWN/$MARK" "$DOWN/$MARK.fpm" /tmp/fpm-write.php >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

echo "== H. El runtime completo forma parte del arranque por defecto =="
expect "sin profiles: runtime completo (bootstrap, theme_build, atom, atom_worker, nginx incluidos)" "atom atom_worker bootstrap elasticsearch gearmand memcached nginx percona theme_build" \
  "$(docker compose config --services | sort | tr '\n' ' ' | sed 's/ $//')"
expect "el runtime no depende de ningún profile" "0" "$(docker compose config --format json | grep -c '"profiles"' || true)"
expect "infra-only sigue siendo posible: seleccionar servicios no arrastra el runtime (dry-run)" \
  "elasticsearch gearmand memcached percona" \
  "$(docker compose up -d --dry-run percona elasticsearch memcached gearmand 2>&1 | grep -oE 'archivo-historico-[a-z_]+-1' | sed -E 's/^archivo-historico-//;s/-1$//' | sort -u | tr '\n' ' ' | sed 's/ $//')"

echo "== Runtime tras el gate =="
atom_up
CID="$(atom_id)"
expect "atom en ejecución" "running" "$(docker inspect -f '{{.State.Status}}' "$CID")"
expect "PHP-FPM operativo (healthcheck)" "healthy" "$(docker inspect -f '{{.State.Health.Status}}' "$CID")"
expect "entrypoint upstream conservado" '["docker/entrypoint.sh"]' "$(docker inspect -f '{{json .Config.Entrypoint}}' "$CID")"
expect "el bootstrap terminó con éxito" "0" "$(docker inspect -f '{{.State.ExitCode}}' "$("${COMPOSE[@]}" ps -aq bootstrap)")"
expect "configuración runtime generada (config.php, propel.ini, search.yml, php-fpm)" "ok" \
  "$(in_atom sh -c 'cd /atom/src && test -s config/config.php && test -s config/propel.ini && test -s config/search.yml && test -s /usr/local/etc/php-fpm.d/atom.conf && echo ok')"
expect "ningún puerto publicado al host" "" "$(docker port "$CID")"

echo "== B. Sin bind del checkout (salvo el plugin del theme, RO) =="
PLUGIN_DST=/atom/src/plugins/arUnicaucaB5Plugin
expect "mounts = uploads y downloads (volúmenes) + el plugin del theme" \
  "bind $PLUGIN_DST;volume $DOWN;volume $UP;" \
  "$(docker inspect -f '{{range .Mounts}}{{.Type}} {{.Destination}};{{end}}' "$CID" | tr ';' '\n' | sort | tr '\n' ';' | sed 's/^;//')"
expect "el único bind es el plugin del theme, en solo lectura (el resto de /atom/src viene de la imagen)" "$PLUGIN_DST false" \
  "$(docker inspect -f '{{range .Mounts}}{{if eq .Type "bind"}}{{.Destination}} {{.RW}}{{end}}{{end}}' "$CID")"

echo "== C/D/I. Escritura y ownership funcional =="
in_atom sh -c "echo uploads-$RUN > $UP/$MARK && echo downloads-$RUN > $DOWN/$MARK"
expect "escritura en uploads" "uploads-$RUN" "$(in_atom cat "$UP/$MARK")"
expect "escritura en downloads" "downloads-$RUN" "$(in_atom cat "$DOWN/$MARK")"
FPM_UP="$(fpm_write "$UP/$MARK.fpm" "fpm-$RUN")"
FPM_DOWN="$(fpm_write "$DOWN/$MARK.fpm" "fpm-$RUN")"
expect "un worker PHP-FPM escribe en uploads" "OK" "${FPM_UP#* }"
expect "un worker PHP-FPM escribe en downloads" "OK" "${FPM_DOWN#* }"

echo "== F. La config no es fuente de verdad: se regenera al recrear =="
in_atom sh -c "echo '# $MARK' >> /atom/src/config/search.yml && touch /atom/src/config/$MARK"
expect "manipulación local visible antes de recrear" "1" "$(in_atom grep -c "$MARK" /atom/src/config/search.yml)"

echo "== E/G. Recrear atom =="
STATE_BEFORE="$("${COMPOSE[@]}" run --rm -T db-probe 2>/dev/null | head -n1)"
CHECK_BEFORE=0
"${COMPOSE[@]}" run --rm --no-deps -T bootstrap php /project/scripts/installation-check.php >/dev/null 2>&1 || CHECK_BEFORE=$?
atom_down
expect "atom eliminado" "" "$(atom_id)"
RECREATE_SINCE="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
atom_up
NEW_CID="$(atom_id)"
expect_ne "contenedor nuevo" "$CID" "$NEW_CID"
expect "PHP-FPM operativo tras recrear" "healthy" "$(docker inspect -f '{{.State.Health.Status}}' "$NEW_CID")"
expect "uploads persiste" "uploads-$RUN" "$(in_atom cat "$UP/$MARK")"
expect "downloads persiste" "downloads-$RUN" "$(in_atom cat "$DOWN/$MARK")"
expect "lo escrito por FPM persiste (uploads)" "fpm-$RUN" "$(in_atom cat "$UP/$MARK.fpm")"
expect "lo escrito por FPM persiste (downloads)" "fpm-$RUN" "$(in_atom cat "$DOWN/$MARK.fpm")"
expect "la writable layer no persiste (marca en config/)" "gone" "$(in_atom sh -c "test -e /atom/src/config/$MARK && echo kept || echo gone")"
expect "search.yml regenerado desde el entorno" "0" "$(in_atom grep -c "$MARK" /atom/src/config/search.yml || true)"
expect "search.yml apunta a Elasticsearch del entorno" "host: elasticsearch" "$(in_atom sh -c "grep -o 'host: elasticsearch' /atom/src/config/search.yml")"
STATE_AFTER="$("${COMPOSE[@]}" run --rm -T db-probe 2>/dev/null | head -n1)"
# Camino NO INSTALL: el bootstrap ejecutado por esta recreación (logs desde RECREATE_SINCE) tomó la rama
# "BD compatible" y nunca lanzó tools:install (literales de scripts/bootstrap.sh).
BOOT_LOG="$(docker logs --since "$RECREATE_SINCE" "$("${COMPOSE[@]}" ps -aq bootstrap)" 2>&1)"
expect "bootstrap de la recreación: BD compatible, no se instala" "1" "$(grep -c 'bootstrap: BD compatible; no se instala' <<<"$BOOT_LOG" || true)"
expect "bootstrap de la recreación: tools:install no ejecutado" "0" "$(grep -c 'ejecutando tools:install' <<<"$BOOT_LOG" || true)"
expect "BD compatible antes" "DB_COMPATIBLE" "$STATE_BEFORE"
expect "BD compatible después" "DB_COMPATIBLE" "$STATE_AFTER"
CHECK_RC=0
"${COMPOSE[@]}" run --rm --no-deps -T bootstrap php /project/scripts/installation-check.php >/dev/null 2>&1 || CHECK_RC=$?
expect "instalación completa antes (installation-check)" "0" "$CHECK_BEFORE"
expect "instalación completa después (installation-check)" "0" "$CHECK_RC"

echo "== Gate negativo: bootstrap fallido → atom no arranca =="
atom_down
GATE_RC=0
ATOM_MYSQL_DSN="mysql:host=percona;port=3306;dbname=nodb$RUN;charset=utf8mb4" \
  BOOTSTRAP_WAIT_ATTEMPTS=2 BOOTSTRAP_WAIT_INTERVAL=1 \
  "${COMPOSE[@]}" up -d --wait atom >/dev/null 2>&1 || GATE_RC=$?
expect_ne "up falla" "0" "$GATE_RC"
# Contrato: con el bootstrap fallido atom no entra en ejecución (su estado exacto es detalle de Compose).
expect_ne "atom no está running" "running" "$(atom_status)"
atom_up
expect "atom restaurado tras el gate negativo" "running" "$(docker inspect -f '{{.State.Status}}' "$(atom_id)")"

echo
if ((fails)); then echo "test-runtime: $fails fallo(s)"; exit 1; fi
echo "test-runtime: OK"
