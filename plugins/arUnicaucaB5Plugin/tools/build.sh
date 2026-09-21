#!/usr/bin/env bash
# Build del theme arUnicaucaB5Plugin. Se ejecuta DENTRO de la imagen AtoM (servicio `theme_build` de compose.yaml):
#
#   docker compose run --rm theme_build
#
# Hace el `npm run build` upstream (Webpack, desde /atom/src) y deja:
#   - dist/ (named volume `theme_dist`, `clean: true`: el build es autoritativo sobre su contenido);
#   - plugins/arUnicaucaB5Plugin/templates/_layout_start.php (derivado, ignorado por Git), dentro del bind RW del plugin.
#
# Ownership: el contenedor corre como root, así que el partial nacería root:root en el checkout del host. Se corrige
# SOLO ese fichero, con el UID:GID del directorio host que lo contiene (leído en ejecución: sin UID/GID fijos,
# sin sudo del host, sin chmod). El `trap EXIT` lo aplica también si el build falla o se interrumpe tras crearlo.
#
# Tras un build correcto se comprueba la coherencia mínima: cada bundle /dist/... que referencia el partial existe
# en dist/. Exit: el del build; si el build fue bien, 1 si el partial es incoherente o el ownership no se pudo tratar.
set -uo pipefail

ATOM_SRC="${ATOM_SRC:-/atom/src}"
PLUGIN=arUnicaucaB5Plugin
TEMPLATES_DIR="$ATOM_SRC/plugins/$PLUGIN/templates"
PARTIAL="$TEMPLATES_DIR/_layout_start.php"

log() { echo "theme-build: $*"; }

fix_ownership() {
  local rc=$?
  trap - EXIT
  if [[ -e "$PARTIAL" ]]; then
    local owner
    if owner="$(stat -c '%u:%g' "$TEMPLATES_DIR")" && chown "$owner" "$PARTIAL"; then
      log "ownership de _layout_start.php -> $owner"
    else
      log "ERROR: no se pudo corregir el ownership de $PARTIAL"
      ((rc != 0)) || rc=1
    fi
  fi
  exit "$rc"
}
trap fix_ownership EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

[[ -d "$TEMPLATES_DIR" ]] || { log "ERROR: no existe $TEMPLATES_DIR (¿falta el bind del plugin?)"; exit 66; }

cd "$ATOM_SRC" || exit 66
log "npm run build (webpack, $ATOM_SRC)"
npm run build || { rc=$?; log "ERROR: el build falló (exit $rc)"; exit "$rc"; }

# Coherencia: el partial existe y todos los bundles que referencia están en dist/.
[[ -s "$PARTIAL" ]] || { log "ERROR: el build no generó $PARTIAL"; exit 1; }
mapfile -t refs < <(grep -oE '/dist/[^"'\''<> ]+\.(js|css)' "$PARTIAL" | sort -u)
((${#refs[@]} > 0)) || { log "ERROR: _layout_start.php no referencia ningún bundle de /dist"; exit 1; }
printf '%s\n' "${refs[@]}" | grep -q "/$PLUGIN\.bundle\." || { log "ERROR: _layout_start.php no referencia los bundles de $PLUGIN"; exit 1; }
missing=0
for ref in "${refs[@]}"; do
  [[ -s "$ATOM_SRC$ref" ]] || { log "ERROR: falta $ref en dist/"; missing=$((missing + 1)); }
done
((missing == 0)) || exit 1
log "OK: ${#refs[@]} bundles referenciados por _layout_start.php existen en dist/"
