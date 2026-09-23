#!/usr/bin/env bash
# Entrypoint del proyecto para los contenedores AtoM (bootstrap, reconcile, atom, atom_worker): deriva la configuración
# runtime CRÍTICA de Symfony y delega en el entrypoint upstream, que sigue haciendo el resto (BD, Memcached, php.ini...).
#
# Qué escribe: SOLO tres claves de apps/qubit/config/settings.yml (y de su settings.yml.tmpl), siempre derivadas de tres
# fuentes. Nada de esto se persiste ni es fuente de verdad: son ficheros de la capa escribible del contenedor, regenerados en
# cada arranque (la imagen y el submódulo upstream no se modifican):
#
#   default_culture   PROJECT-MANAGED             constante de este script (PROJECT_DEFAULT_CULTURE)
#   default_timezone  ENVIRONMENT-MANAGED         ATOM_PHP_DATE_TIMEZONE, la MISMA variable que el entrypoint upstream vuelca en
#                                                 php.ini (`date.timezone`): una sola intención, dos consumidores, sin divergencia posible
#   csrf_secret       ENVIRONMENT-MANAGED SECRET  el contenido del fichero ATOM_CSRF_SECRET_FILE (nunca el valor en el entorno)
#
# Por qué existe: upstream copia settings.yml.tmpl tal cual (`en`, `America/Vancouver`, `csrf_secret: change_me`) y
# `tools:install` solo genera un secreto en el contenedor que instala. Peor: `tools:install` BORRA settings.yml y lo regenera
# desde settings.yml.tmpl antes de recargar la configuración, así que un settings.yml ya correcto no basta: la instalación
# correría con `en`. Por eso los mismos valores se derivan también en el .tmpl (copia del contenedor), y la instalación nace con la
# cultura y el timezone del proyecto y con el secreto real (no queda `change_me` que sustituir). Todos los contextos comparten el secreto.
#
# Este script NO genera secretos (en DEV los aporta el servicio `dev_secrets`; un despliegue real aporta su propio fichero) y NO
# escribe en la BD ni migra nada: cambiar el default no reescribe source_culture ni timestamps existentes.
#
# Entorno: ATOM_PHP_DATE_TIMEZONE (obligatoria; un identificador de zona horaria de PHP), ATOM_CSRF_SECRET_FILE (obligatoria),
# ATOM_SRC (/atom/src). Nunca imprime el secreto.
#
# Exit: 64 entorno inválido (timezone o fichero de secreto ausente/ilegible/inválido);
#       65 el template upstream ya no tiene la forma esperada (no se aplica nada a ciegas).
set -euo pipefail

ATOM_SRC="${ATOM_SRC:-/atom/src}"
CONFIG_DIR="$ATOM_SRC/apps/qubit/config"
TEMPLATE="$CONFIG_DIR/settings.yml.tmpl"
SETTINGS="$CONFIG_DIR/settings.yml"

# PROJECT-MANAGED: cultura por defecto de la interfaz y de los objetos creados sin cultura explícita.
PROJECT_DEFAULT_CULTURE=es

# Alfabeto seguro para YAML sin comillas y longitud mínima; excluye por construcción el `change_me` de upstream.
SECRET_RE='^[A-Za-z0-9_-]{32,}$'

log() { echo "runtime-config: $*" >&2; }

# Deja el secreto en SECRET (sin imprimirlo).
read_secret() {
  local file="${ATOM_CSRF_SECRET_FILE:-}"
  if [[ -z "$file" ]]; then log "falta ATOM_CSRF_SECRET_FILE"; return 64; fi
  if [[ ! -f "$file" || ! -r "$file" ]]; then log "no se puede leer el fichero de secreto CSRF: $file"; return 64; fi
  SECRET=
  IFS= read -r SECRET <"$file" || [[ -n "$SECRET" ]] || true
  if [[ ! "$SECRET" =~ $SECRET_RE ]]; then
    log "el secreto CSRF de $file no es válido (se esperan >= 32 caracteres de [A-Za-z0-9_-])"
    return 64
  fi
}

check_timezone() {
  local tz="${ATOM_PHP_DATE_TIMEZONE:-}"
  if [[ -z "$tz" ]]; then log "falta ATOM_PHP_DATE_TIMEZONE"; return 64; fi
  php -r 'exit(in_array($argv[1], timezone_identifiers_list(), true) ? 0 : 1);' -- "$tz" \
    || { log "ATOM_PHP_DATE_TIMEZONE no es una zona horaria de PHP válida: '$tz'"; return 64; }
}

# Deriva settings.yml (y el .tmpl) sustituyendo exactamente una vez cada clave; si alguna no aparece, upstream cambió y no se escribe
# nada. Acepta cualquier valor previo: es idempotente (un contenedor reiniciado ya tiene el .tmpl derivado).
render_settings() {
  local tmp line n_secret=0 n_culture=0 n_tz=0
  [[ -r "$TEMPLATE" ]] || { log "no existe $TEMPLATE"; return 65; }
  tmp="$(mktemp "$CONFIG_DIR/.settings.yml.XXXXXX")"
  while IFS= read -r line || [[ -n "$line" ]]; do
    if [[ "$line" =~ ^([[:space:]]+csrf_secret:[[:space:]]+)[^[:space:]#]+[[:space:]]*$ ]]; then
      line="${BASH_REMATCH[1]}$SECRET"; n_secret=$((n_secret + 1))
    elif [[ "$line" =~ ^([[:space:]]+default_culture:[[:space:]]+)[^[:space:]#]+[[:space:]]*$ ]]; then
      line="${BASH_REMATCH[1]}$PROJECT_DEFAULT_CULTURE"; n_culture=$((n_culture + 1))
    elif [[ "$line" =~ ^([[:space:]]+default_timezone:[[:space:]]+)[^[:space:]#]+[[:space:]]*$ ]]; then
      line="${BASH_REMATCH[1]}$ATOM_PHP_DATE_TIMEZONE"; n_tz=$((n_tz + 1))
    fi
    printf '%s\n' "$line" >>"$tmp"
  done <"$TEMPLATE"
  if ((n_secret != 1 || n_culture != 1 || n_tz != 1)); then
    rm -f "$tmp"
    log "settings.yml.tmpl no tiene la forma esperada (csrf_secret=$n_secret default_culture=$n_culture default_timezone=$n_tz): no se aplica nada"
    return 65
  fi
  chmod 600 "$tmp"
  # El .tmpl primero (con el secreto: 0600), luego settings.yml; ambos con el mismo contenido.
  cat "$tmp" >"$TEMPLATE"
  chmod 600 "$TEMPLATE"
  mv -f "$tmp" "$SETTINGS"
}

main() {
  read_secret || exit $?
  check_timezone || exit $?
  render_settings || exit $?
  # El entrypoint upstream conserva un settings.yml existente y hace el resto de la configuración runtime.
  exec "$ATOM_SRC/docker/entrypoint.sh" "$@"
}

# Permite `source` este archivo (pruebas) sin ejecutar main.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main "$@"; fi
