#!/usr/bin/env bash
# Prueba de integración del reconcile de `require_strong_passwords = 1` (config/atom/reconcile-strong-passwords.php) contra el Compose DEV.
#
#   config/atom/tests/test-reconcile-strong-passwords.sh
#
# Aislamiento: los casos que alteran el setting corren sobre una BD SCRATCH clonada de `atom` (id aleatorio, usuario propio con permisos
# solo sobre ella), que se elimina al terminar. La caché de settings (Memcached) es compartida con la DEV; es derivada y la última
# sección la invalida con un reconcile real, así ningún valor scratch sobrevive. La observación es SQL directo, independiente del script.
# La BD `atom` real solo se toca en la sección final (reconcile repetido sin cambios); nunca `down -v`.
# Requiere el runtime arriba (`docker compose up -d --wait`).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
COMPOSE=(docker compose)
ROOT_PW="${MYSQL_ROOT_PASSWORD:-my-secret-pw}"
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
SCRATCH="rs$RUN" # sin "_": en GRANT, "_" de un nombre de base es un comodín
USR="su$RUN"
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
expect_out() { # <descripción> <regex que debe aparecer en $OUT>
  if grep -Eq "$2" <<<"$OUT"; then echo "PASS  $1"; else echo "FAIL  $1 (no aparece /$2/ en: ${OUT:0:800})"; fails=$((fails + 1)); fi
}

admin() { "${COMPOSE[@]}" exec -T percona mysql --default-character-set=utf8mb4 -uroot -p"$ROOT_PW" --batch --skip-column-names "$@" 2>/dev/null; }
cleanup() {
  ((CREATED_DB)) && admin -e "DROP DATABASE \`$SCRATCH\`" || true
  ((CREATED_USER)) && admin -e "DROP USER '$USR'@'%'" || true
  rm -rf "$TMP"
}
trap cleanup EXIT

# --- observación independiente (SQL) ---
rsp() { # <db> → "source_culture/culture=valor" por fila i18n de require_strong_passwords
  admin "$1" -e "SELECT CONCAT_WS('/', s.source_culture, CONCAT(i.culture, '=', i.value)) FROM setting s JOIN setting_i18n i ON i.id = s.id WHERE s.name = 'require_strong_passwords' ORDER BY i.culture" | tr '\n' ' '
}
others() { # <db> → huella de TODAS las demás filas de settings (incluido check_for_updates)
  admin "$1" -e "SELECT CONCAT_WS('~', s.id, s.name, IFNULL(s.scope,''), s.source_culture, IFNULL(i.culture,''), IFNULL(i.value,'')) FROM setting s
    LEFT JOIN setting_i18n i ON i.id = s.id WHERE s.name <> 'require_strong_passwords' ORDER BY s.id, i.culture" | sha256sum | cut -c1-16
}
fp() { admin "$1" -e "CHECKSUM TABLE setting, setting_i18n" | tr -s '\t' ' ' | tr '\n' ';'; }

# --- ejecución contra la BD scratch ---
in_scratch() { # <comando...> → $OUT y $RC
  RC=0
  OUT=$("${COMPOSE[@]}" run --rm --no-deps -T \
    -e ATOM_MYSQL_DSN="mysql:host=percona;port=3306;dbname=$SCRATCH;charset=utf8mb4" \
    -e ATOM_MYSQL_USERNAME="$USR" -e ATOM_MYSQL_PASSWORD="$PW" \
    reconcile "$@" 2>&1) || RC=$?
}
reconcile_rsp() { in_scratch bash -c 'cd /atom/src && php symfony tools:run /project/scripts/reconcile-strong-passwords.php'; }

echo "== preparación: BD scratch clonada de atom =="
"${COMPOSE[@]}" up -d --wait percona >/dev/null
DEV_FP_BEFORE="$(fp atom)"
admin -e "CREATE DATABASE \`$SCRATCH\`"; CREATED_DB=1
admin -e "CREATE USER '$USR'@'%' IDENTIFIED BY '$PW'"; CREATED_USER=1
admin -e "GRANT ALL PRIVILEGES ON \`$SCRATCH\`.* TO '$USR'@'%'"
"${COMPOSE[@]}" exec -T percona sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" atom 2>/dev/null | mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" $SCRATCH 2>/dev/null"
setv() { admin "$SCRATCH" -e "UPDATE setting_i18n i JOIN setting s ON s.id = i.id AND i.culture = s.source_culture SET i.value = '$1' WHERE s.name = 'require_strong_passwords'"; }

echo "== Contrato estático =="
CFG="$("${COMPOSE[@]}" config --format json)"
expect "reconcile monta el script RO" "True" \
  "$(python3 -c "import json,sys; v=[x for x in json.load(sys.stdin)['services']['reconcile']['volumes'] if x['target']=='/project/scripts/reconcile-strong-passwords.php']; print(len(v)==1 and v[0].get('read_only') is True)" <<<"$CFG")"
expect "reconcile.sh ejecuta el paso tras el de términos" "1" \
  "$(grep -A1 'reconcile-terms.php"$' config/atom/reconcile.sh | grep -c 'tools:run "$HERE/reconcile-strong-passwords.php"' || true)"
expect "el script no usa flush_all" "0" "$(grep -vE '^[[:space:]]*(/?\*|//)' config/atom/reconcile-strong-passwords.php | grep -ci 'flush' || true)"

echo "== A. Desde el default upstream (0) → 1 =="
setv 0
OTHERS0="$(others "$SCRATCH")"
expect "precondición: 0" "es/es=0 " "$(rsp "$SCRATCH")"
reconcile_rsp
expect "exit 0" "0" "$RC"
expect_out "escribió y verificó" "require_strong_passwords: escrito 1 en la BD"
expect_out "postcondición" "OK: BD = 1, valor efectivo = 1"
expect "valor 1 en la BD" "es/es=1 " "$(rsp "$SCRATCH")"
expect "el resto de settings intacto" "$OTHERS0" "$(others "$SCRATCH")"

echo "== B. Idempotencia =="
FP1="$(fp "$SCRATCH")"
reconcile_rsp
expect "segundo run: exit 0" "0" "$RC"
expect_out "sin escritura" "ya es 1 en la BD; sin escritura"
expect "tablas de settings idénticas" "$FP1" "$(fp "$SCRATCH")"

echo "== C. Override en otra cultura → STOP (84) antes de escribir =="
setv 0
admin "$SCRATCH" -e "INSERT INTO setting_i18n (id, culture, value) SELECT id, 'fr', '0' FROM setting WHERE name = 'require_strong_passwords'"
FP2="$(fp "$SCRATCH")"
reconcile_rsp
expect "exit 84" "84" "$RC"
expect_out "NO RECONCILIABLE" "NO RECONCILIABLE"
expect "ninguna escritura" "$FP2" "$(fp "$SCRATCH")"
admin "$SCRATCH" -e "DELETE i FROM setting_i18n i JOIN setting s ON s.id = i.id WHERE s.name = 'require_strong_passwords' AND i.culture = 'fr'"

echo "== D. Fila ausente → se crea con 1 =="
admin "$SCRATCH" -e "DELETE i FROM setting_i18n i JOIN setting s ON s.id = i.id WHERE s.name = 'require_strong_passwords'; DELETE FROM setting WHERE name = 'require_strong_passwords'"
reconcile_rsp
expect "exit 0" "0" "$RC"
expect_out "creada" "no existía; creada con valor 1"
expect "valor 1" "1" "$(admin "$SCRATCH" -e "SELECT COUNT(*) FROM setting s JOIN setting_i18n i ON i.id = s.id WHERE s.name = 'require_strong_passwords' AND i.value = '1'")"

expect "la BD real no cambió durante los casos scratch" "$DEV_FP_BEFORE" "$(fp atom)"

echo "== E. Reconcile real sobre la DEV (invalida la caché compartida) =="
FP_DEV="$(fp atom)"
"${COMPOSE[@]}" run --rm --no-deps -T reconcile >"$TMP/rec.log" 2>&1 || true
expect "reconcile completo: exit 0 (verificado por su salida)" "1" "$(grep -c 'require_strong_passwords: OK: BD = 1' "$TMP/rec.log" || true)"
expect "DEV: valor 1" "es/es=1 " "$(rsp atom)"
expect "DEV: repetido sin escritura" "$FP_DEV" "$(fp atom)"

echo
if ((fails)); then echo "test-reconcile-strong-passwords: $fails fallo(s)"; exit 1; fi
echo "test-reconcile-strong-passwords: OK"
