#!/usr/bin/env bash
# Bootstrap DEV de AtoM: instala una BD FRESH de forma segura.
# El probe es la autoridad del estado de BD y installation-check verifica que la instalación esté completa.
#
# Corre dentro del runtime AtoM (necesita php, curl y el checkout en ATOM_SRC). En DEV lo ejecuta
# el servicio `bootstrap` del Compose, que conserva el entrypoint upstream (genera la configuración
# runtime a partir del entorno).
#
#   probe → DB_COMPATIBLE       : no instala; installation-check → éxito solo si la instalación está completa
#         → DB_FRESH            : espera Elasticsearch y Memcached → tools:install (una vez) → probe obligatorio
#                                 (DB_COMPATIBLE) → installation-check obligatorio → éxito
#         → DB_UNKNOWN / DB_SCHEMA_MISMATCH / 64 / 70 : STOP, sin instalar ni actualizar
#         → DB_UNREACHABLE      : reintento acotado; si se agota, STOP
#
# Espera/reintento (única política, para la BD y para Elasticsearch):
#   BOOTSTRAP_WAIT_ATTEMPTS (30) intentos, BOOTSTRAP_WAIT_INTERVAL (2) segundos entre ellos.
#
# Entorno: el del probe (ATOM_MYSQL_DSN/USERNAME/PASSWORD) más ATOM_ELASTICSEARCH_HOST, ATOM_MEMCACHED_HOST y, solo si hay
# que instalar, ATOM_ADMIN_EMAIL/USERNAME/PASSWORD, ATOM_SITE_TITLE/DESCRIPTION/BASE_URL y
# ATOM_SEARCH_INDEX. tools:install apunta a la misma BD que observó el probe (se deriva del DSN).
#
# DB_COMPATIBLE solo significa "AtoM con el schema esperado": un tools:install interrumpido puede dejar
# esa BD incompleta, por eso installation-check.php (invariante: admin en acl_user_group, grupo 100)
# es un requisito aparte de todo éxito. Una BD incompleta no se repara ni se reinstala aquí (STOP): si es desechable, RESET DEV; si hay datos que conservar, recuperación explícita.
#
# Exit: 0 éxito; 20/21/30/64/70 el del probe (STOP); 40 tools:install falló;
#       41 el probe posterior no dio DB_COMPATIBLE; 42 dependencia (Elasticsearch/Memcached) no disponible;
#       43 BD DB_COMPATIBLE pero instalación incompleta (installation-check).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ATOM_SRC="${ATOM_SRC:-/atom/src}"
WAIT_ATTEMPTS="${BOOTSTRAP_WAIT_ATTEMPTS:-30}"
WAIT_INTERVAL="${BOOTSTRAP_WAIT_INTERVAL:-2}"

log() { echo "bootstrap: $*"; }

# Deja el resultado en PROBE_STATE / PROBE_RC (el stderr del probe pasa tal cual).
run_probe() {
  PROBE_RC=0
  PROBE_STATE="$(php "$HERE/db-probe.php")" || PROBE_RC=$?
}

# Deja el resultado en CHECK_RC (0 completa, 1 incompleta, 64/70 error).
run_check() {
  CHECK_RC=0
  php "$HERE/installation-check.php" >/dev/null || CHECK_RC=$?
}

# Exige instalación completa; si no, sale con 43 (o con el 64/70 del check).
require_complete_install() {
  run_check
  case "$CHECK_RC" in
    0) log "instalación completa verificada (administrador en el grupo 100)" ;;
    1) log "instalación INCOMPLETA: la BD es compatible pero no tiene administrador. STOP: no se reinstala ni se repara automáticamente. Si esta instancia DEV es desechable: RESET DEV. Si hay datos que conservar: NO hacer RESET; investigar y recuperar explícitamente"; exit 43 ;;
    *) log "installation-check falló (exit $CHECK_RC)"; exit "$CHECK_RC" ;;
  esac
}

probe_with_retry() {
  local n=1
  while :; do
    run_probe
    if ((PROBE_RC != 30 || n >= WAIT_ATTEMPTS)); then return; fi
    log "BD inalcanzable (intento $n/$WAIT_ATTEMPTS); reintento en ${WAIT_INTERVAL}s"
    sleep "$WAIT_INTERVAL"
    n=$((n + 1))
  done
}

es_hostport() {
  local hp="${ATOM_ELASTICSEARCH_HOST:-}"
  [[ -n "$hp" ]] || return 1
  [[ "$hp" == *:* ]] || hp+=":9200"
  echo "$hp"
}

es_ready() { curl -fsS --max-time 5 "http://$(es_hostport)/_cluster/health?wait_for_status=yellow&timeout=2s" >/dev/null; }

memcached_ready() (
  local hp="${ATOM_MEMCACHED_HOST:-}" line
  [[ "$hp" == *:* ]] || hp+=":11211"
  exec 3<>"/dev/tcp/${hp%%:*}/${hp##*:}"
  printf 'version\r\n' >&3
  read -t 2 -r line <&3
  [[ "$line" == VERSION* ]]
)

# wait_for <nombre> <comando...>: espera acotada (política única) a que el comando tenga éxito.
wait_for() {
  local name=$1 n=1; shift
  until "$@" >/dev/null 2>&1; do
    if ((n >= WAIT_ATTEMPTS)); then log "$name no disponible tras $n intentos"; return 1; fi
    log "esperando $name (intento $n/$WAIT_ATTEMPTS)"
    sleep "$WAIT_INTERVAL"
    n=$((n + 1))
  done
  log "$name disponible"
}

# Dependencias que tools:install necesita realmente (Elasticsearch y Memcached; la BD ya se observó).
wait_for_dependencies() {
  [[ -n "${ATOM_ELASTICSEARCH_HOST:-}" && -n "${ATOM_MEMCACHED_HOST:-}" ]] \
    || { log "faltan ATOM_ELASTICSEARCH_HOST o ATOM_MEMCACHED_HOST"; exit 64; }
  wait_for "Elasticsearch ($ATOM_ELASTICSEARCH_HOST)" es_ready && wait_for "Memcached ($ATOM_MEMCACHED_HOST)" memcached_ready
}

dsn_field() { [[ "$ATOM_MYSQL_DSN" =~ (^|[:;])$1=([^;]*) ]] && echo "${BASH_REMATCH[2]}"; }

# Único punto que ejecuta tools:install. Solo se llama tras DB_FRESH.
run_install() {
  local host port name es es_host es_port
  host="$(dsn_field host)" && name="$(dsn_field dbname)" || { log "el DSN debe incluir host y dbname"; exit 64; }
  port="$(dsn_field port || true)"
  es="$(es_hostport)"; es_host="${es%%:*}"; es_port="${es##*:}"
  : "${ATOM_ADMIN_EMAIL:?}" "${ATOM_ADMIN_USERNAME:?}" "${ATOM_ADMIN_PASSWORD:?}"

  cd "$ATOM_SRC"
  # tools:install imprime las contraseñas de la configuración; se omiten del log.
  php symfony tools:install --no-confirmation \
    --database-host="$host" --database-port="${port:-3306}" --database-name="$name" \
    --database-user="$ATOM_MYSQL_USERNAME" --database-password="$ATOM_MYSQL_PASSWORD" \
    --search-host="$es_host" --search-port="$es_port" --search-index="${ATOM_SEARCH_INDEX:-atom}" \
    --site-title="${ATOM_SITE_TITLE:-AtoM}" --site-description="${ATOM_SITE_DESCRIPTION:-Access to Memory}" \
    --site-base-url="${ATOM_SITE_BASE_URL:-http://127.0.0.1}" \
    --admin-email="$ATOM_ADMIN_EMAIL" --admin-username="$ATOM_ADMIN_USERNAME" --admin-password="$ATOM_ADMIN_PASSWORD" \
    2>&1 | sed -E '/^(Database|Admin) password /d'
}

install_flow() {
  wait_for_dependencies || exit 42
  log "BD FRESH: ejecutando tools:install (una sola vez)"
  run_install || { log "tools:install falló"; exit 40; }
  run_probe
  if ((PROBE_RC != 0)) || [[ "$PROBE_STATE" != "DB_COMPATIBLE" ]]; then
    log "instalación NO verificada: el probe posterior dio ${PROBE_STATE:-sin estado} (exit $PROBE_RC)"
    exit 41
  fi
  log "post-probe: DB_COMPATIBLE"
  require_complete_install
  log "instalación verificada"
  exit 0
}

main() {
  probe_with_retry
  log "probe: ${PROBE_STATE:-sin estado} (exit $PROBE_RC)"
  case "$PROBE_RC" in
    0) log "BD compatible; no se instala"; require_complete_install; exit 0 ;;
    10) install_flow ;;
    20 | 21 | 30 | 64 | 70) log "STOP sin instalar"; exit "$PROBE_RC" ;;
    *) log "exit inesperado del probe; STOP"; exit 70 ;;
  esac
}

# Permite `source` este archivo (pruebas) sin ejecutar main.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main; fi
