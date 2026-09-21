#!/usr/bin/env bash
# RECONCILE DEV de plugins de AtoM: garantiza que cada plugin de config/atom/required-plugins.conf esté habilitado.
#
# Corre dentro del runtime AtoM (necesita php y el checkout en ATOM_SRC). En DEV lo ejecuta el servicio `reconcile`
# del Compose, después del bootstrap y antes de `atom` / `atom_worker` (gate pre-start). Conserva el entrypoint upstream
# (genera la configuración runtime desde el entorno) y monta RO el plugin, este script y el desired state.
#
# Alcance: SOLO la propiedad "el plugin requerido está habilitado". Selectivo (no gestiona plugins ajenos), aditivo
# (solo añade), idempotente (si ya está habilitado no escribe) y no destructivo (nunca quita ni deshabilita nada).
# No instala, no actualiza, no siembra, no compila assets ni limpia cachés.
#
# Por cada plugin requerido:
#   validar entrada (formato del .conf) → validar source mínimo del plugin → observar (`tools:atom-plugins list`)
#   → si falta, `tools:atom-plugins add` → volver a observar y exigir la postcondición.
#
# Por qué se valida el source y se re-observa: `tools:atom-plugins add` guarda CUALQUIER nombre (incluso uno inexistente)
# y sale con 0; AtoM ignora en silencio un plugin de la BD que no existe en el filesystem. El exit 0 de la CLI no
# prueba que la propiedad se cumpla.
#
# Postcondición tras escribir: el plugin figura en la lista Y la lista es exactamente la observada antes + ese plugin
# (ningún plugin ajeno desapareció ni apareció).
#
# Entorno: ATOM_SRC (/atom/src), RECONCILE_REQUIRED_PLUGINS (/project/config/atom/required-plugins.conf) y el de la
# conexión a la BD que usa el entrypoint upstream (ATOM_MYSQL_*).
#
# Supuesto: un único writer durante el gate pre-start. No hay locking ni se asume hot-reconcile sobre un AtoM ya iniciado.
#
# Exit: 0 éxito; 64 entrada inválida (falta el .conf, está vacío o tiene una línea inválida);
#       65 source mínimo del plugin inválido; 70 no se pudo observar la lista de plugins;
#       71 `tools:atom-plugins add` falló; 72 postcondición no satisfecha tras añadir.
set -euo pipefail

ATOM_SRC="${ATOM_SRC:-/atom/src}"
REQUIRED_FILE="${RECONCILE_REQUIRED_PLUGINS:-/project/config/atom/required-plugins.conf}"
# Convención de nombres de plugins de AtoM (arFooPlugin, sfFooPlugin, qbFooPlugin...). Excluye rutas, espacios y metacaracteres.
PLUGIN_NAME_RE='^[A-Za-z][A-Za-z0-9]*Plugin$'

log() { echo "reconcile: $*"; }

# Deja los plugins requeridos, sin duplicados y en orden de aparición, en REQUIRED. Valida todo el fichero.
read_required() {
  REQUIRED=()
  if [[ ! -f "$REQUIRED_FILE" || ! -r "$REQUIRED_FILE" ]]; then
    log "no se puede leer el desired state: $REQUIRED_FILE"
    return 1
  fi
  local line n=0 bad=0 seen=" "
  while IFS= read -r line || [[ -n "$line" ]]; do
    n=$((n + 1))
    line="${line%%#*}"
    line="${line#"${line%%[![:space:]]*}"}"
    line="${line%"${line##*[![:space:]]}"}"
    [[ -n "$line" ]] || continue
    if [[ ! "$line" =~ $PLUGIN_NAME_RE ]]; then
      log "línea $n de $REQUIRED_FILE inválida (se espera un nombre de plugin, p. ej. arFooPlugin): '$line'"
      bad=$((bad + 1))
      continue
    fi
    [[ "$seen" == *" $line "* ]] && continue
    seen+="$line "
    REQUIRED+=("$line")
  done <"$REQUIRED_FILE"
  ((bad == 0)) || return 1
  if ((${#REQUIRED[@]} == 0)); then
    log "$REQUIRED_FILE no declara ningún plugin requerido"
    return 1
  fi
}

# Source mínimo: el plugin existe en el filesystem de AtoM, su clase de Configuration existe, declara la clase esperada y
# es PHP sintácticamente válido. No ejecuta el plugin.
validate_source() { # <plugin>
  local plugin=$1 dir="$ATOM_SRC/plugins/$1" cfg
  cfg="$dir/config/${plugin}Configuration.class.php"
  if [[ ! -d "$dir" ]]; then log "$plugin: no existe $dir (¿falta montar el plugin?)"; return 1; fi
  if [[ ! -r "$cfg" ]]; then log "$plugin: falta o no se puede leer $cfg"; return 1; fi
  if ! grep -Eq "^(final +|abstract +)?class +${plugin}Configuration([[:space:]]|$)" "$cfg"; then
    log "$plugin: $cfg no declara la clase ${plugin}Configuration"
    return 1
  fi
  if ! php -l "$cfg" >/dev/null 2>&1; then log "$plugin: $cfg no es PHP válido"; return 1; fi
}

# Costuras de la CLI de AtoM (las pruebas las redefinen).
atom_plugins_list() { (cd "$ATOM_SRC" && php symfony tools:atom-plugins list); }
atom_plugins_add() { (cd "$ATOM_SRC" && php symfony tools:atom-plugins add "$1"); }

# Deja en OBSERVED los plugins habilitados que reporta AtoM (una línea = un nombre; cualquier otra salida se ignora).
observe() {
  local out line
  OBSERVED=()
  out="$(atom_plugins_list)" || return 1
  while IFS= read -r line; do
    [[ "$line" =~ $PLUGIN_NAME_RE ]] && OBSERVED+=("$line")
  done <<<"$out"
  return 0
}

is_observed() { # <plugin>
  local p
  for p in ${OBSERVED[@]+"${OBSERVED[@]}"}; do [[ "$p" == "$1" ]] && return 0; done
  return 1
}

sorted() { printf '%s\n' "$@" | LC_ALL=C sort -u; }

reconcile_plugin() { # <plugin>
  local plugin=$1 before after expected
  observe || { log "no se pudo observar la lista de plugins (tools:atom-plugins list)"; return 70; }
  if is_observed "$plugin"; then
    log "$plugin: ya habilitado; sin cambios"
    return 0
  fi
  before="$(sorted ${OBSERVED[@]+"${OBSERVED[@]}"})"
  log "$plugin: no habilitado; añadiendo con tools:atom-plugins add"
  atom_plugins_add "$plugin" >/dev/null || { log "$plugin: tools:atom-plugins add falló"; return 71; }
  observe || { log "$plugin: no se pudo re-observar tras añadir"; return 72; }
  if ! is_observed "$plugin"; then
    log "$plugin: POSTCONDICIÓN NO SATISFECHA: tras añadir, no figura como habilitado"
    return 72
  fi
  after="$(sorted "${OBSERVED[@]}")"
  expected="$(sorted ${before:+$before} "$plugin")"
  if [[ "$after" != "$expected" ]]; then
    log "$plugin: POSTCONDICIÓN NO SATISFECHA: la lista de plugins cambió más allá de añadir $plugin"
    return 72
  fi
  log "$plugin: habilitado y verificado (los demás plugins intactos)"
}

main() {
  local plugin rc bad=0
  read_required || exit 64
  log "desired state: ${REQUIRED[*]}"
  # Todo el source se valida antes de escribir nada.
  for plugin in "${REQUIRED[@]}"; do validate_source "$plugin" || bad=$((bad + 1)); done
  ((bad == 0)) || { log "STOP: source de plugin inválido; no se modificó nada"; exit 65; }
  for plugin in "${REQUIRED[@]}"; do
    rc=0
    reconcile_plugin "$plugin" || rc=$?
    ((rc == 0)) || exit "$rc"
  done
  log "OK"
}

# Permite `source` este archivo (pruebas) sin ejecutar main.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main; fi
