#!/usr/bin/env bash
# Proveedor de secretos SOLO PARA DEV: garantiza que exista el secreto CSRF local en un volumen dedicado.
#
# Lo ejecuta el servicio `dev_secrets` (one-shot, antes de `bootstrap`). Escribe /run/atom-secrets/csrf_secret UNA vez: si ya
# existe y es válido no lo toca (el secreto es estable mientras exista el volumen; sobrevive a stop/down/recreate). Solo un
# RESET DEV (`docker compose down -v`, que elimina el volumen) provoca un secreto nuevo en el siguiente `up`. Nunca imprime el valor.
#
# El secreto NO va en Git, ni en la imagen, ni en el entorno de ningún contenedor (no aparece en `docker inspect`); los
# contenedores AtoM lo reciben como un fichero de solo lectura y config/atom/runtime-config.sh lo vuelca en su settings.yml
# efímero. Un despliegue real NO usa este servicio: aporta su propio fichero y apunta ATOM_CSRF_SECRET_FILE a él.
#
# Un fichero existente pero inválido (vacío, corto, con caracteres inesperados) es STOP: no se sustituye en silencio.
#
# Entorno: ATOM_SECRETS_DIR (/run/atom-secrets).
# Exit: 0 éxito; 1 fichero existente inválido o no se pudo escribir.
set -euo pipefail

DIR="${ATOM_SECRETS_DIR:-/run/atom-secrets}"
FILE="$DIR/csrf_secret"
SECRET_RE='^[A-Za-z0-9_-]{32,}$' # mismo contrato que config/atom/runtime-config.sh

log() { echo "dev-secrets: $*"; }

valid() { # <fichero>
  local s=
  IFS= read -r s <"$1" || [[ -n "$s" ]] || true
  [[ "$s" =~ $SECRET_RE ]]
}

main() {
  umask 077
  if [[ -e "$FILE" ]]; then
    valid "$FILE" || { log "STOP: $FILE existe pero no es un secreto válido; no se sustituye. Si esta instancia DEV es desechable: RESET DEV"; exit 1; }
    log "secreto CSRF presente y válido; sin cambios"
    return 0
  fi
  local tmp
  tmp="$(mktemp "$DIR/.csrf_secret.XXXXXX")"
  # 32 bytes de /dev/urandom (CSPRNG del kernel) en hexadecimal: 256 bits.
  head -c 32 /dev/urandom | od -An -vtx1 | tr -d ' \n' >"$tmp"
  valid "$tmp" || { rm -f "$tmp"; log "el secreto generado no es válido"; exit 1; }
  mv "$tmp" "$FILE"
  log "secreto CSRF generado (nuevo)"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main; fi
