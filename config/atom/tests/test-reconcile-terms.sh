#!/usr/bin/env bash
# Prueba de integración del reconcile de términos PROJECT-MANAGED (config/atom/reconcile-terms.php) contra el Compose DEV.
#
#   config/atom/tests/test-reconcile-terms.sh
#
# Aislamiento: los casos que alteran términos (ausentes, ajenos, homónimos, renombrados) corren sobre una BD SCRATCH clonada de
# `atom` (id aleatorio, usuario propio con permisos solo sobre ella), que se elimina al terminar. En la BD scratch el reconcile corre
# con la indexación de búsqueda deshabilitada (QubitSearch::disable() en un wrapper de la prueba, no en el producto): los ids de la BD
# scratch no deben llegar al índice de Elasticsearch de la DEV. La observación es SQL directo, independiente del script bajo prueba.
# La BD `atom` real solo se toca en la sección final (happy path con `up`, el uso normal); nunca `down -v`.
# Requiere el runtime arriba (`docker compose up -d --wait`).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
COMPOSE=(docker compose)
ROOT_PW="${MYSQL_ROOT_PASSWORD:-my-secret-pw}"
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
SCRATCH="rt$RUN" # sin "_": en GRANT, "_" de un nombre de base es un comodín
USR="tu$RUN"
PW="pw$RUN"
TMP="$(mktemp -d)"
fails=0
OUT=
RC=0
CREATED_DB=0
CREATED_USER=0
SEC=archivo-historico:seccion
REM=archivo-historico:remitente
DES=archivo-historico:destinatario
CREATION_ID=111 # QubitTerm::CREATION_ID (término estándar; solo como padre ajeno en el caso de deriva)

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
term() { # <db> <code> → "taxonomía|padre|cultura fuente|es|en" (una línea por fila con ese code)
  admin "$1" -e "SELECT CONCAT_WS('|', t.taxonomy_id, t.parent_id, t.source_culture,
      (SELECT name FROM term_i18n WHERE id = t.id AND culture = 'es'), (SELECT name FROM term_i18n WHERE id = t.id AND culture = 'en'))
    FROM term t WHERE t.code = '$2' COLLATE utf8mb4_bin"
}
count_code() { admin "$1" -e "SELECT COUNT(*) FROM term WHERE code = '$2' COLLATE utf8mb4_bin"; }
count_name() { admin "$1" -e "SELECT COUNT(DISTINCT t.id) FROM term t JOIN term_i18n i ON i.id = t.id WHERE t.taxonomy_id = $2 AND i.name = '$3' COLLATE utf8mb4_bin"; }
fp() { admin "$1" -e "CHECKSUM TABLE object, term, term_i18n, slug" | tr -s '\t' ' ' | tr '\n' ';'; } # cualquier escritura
foreign_fp() { # <db> → huella de los términos que NO son del proyecto (sin lft/rgt)
  admin "$1" -e "SELECT t.id, t.taxonomy_id, t.code, t.parent_id, t.source_culture, i.culture, i.name FROM term t LEFT JOIN term_i18n i ON i.id = t.id
    WHERE t.code IS NULL OR t.code NOT LIKE 'archivo-historico:%' ORDER BY t.id, i.culture" | sha256sum | cut -c1-16
}

# --- ejecución contra la BD scratch ---
cat >"$TMP/wrap.php" <<'EOF'
<?php
QubitSearch::disable();
require '/project/scripts/reconcile-terms.php';
EOF
in_scratch() { # [-v mount ...] -- <comando...> → $OUT y $RC
  local opts=()
  while [[ $1 != -- ]]; do opts+=("$1"); shift; done
  shift
  RC=0
  OUT=$("${COMPOSE[@]}" run --rm --no-deps -T \
    -e ATOM_MYSQL_DSN="mysql:host=percona;port=3306;dbname=$SCRATCH;charset=utf8mb4" \
    -e ATOM_MYSQL_USERNAME="$USR" -e ATOM_MYSQL_PASSWORD="$PW" -v "$TMP:/t:ro" \
    ${opts[@]+"${opts[@]}"} reconcile "$@" 2>&1) || RC=$?
}
reconcile_terms() { in_scratch -- bash -c 'cd /atom/src && php symfony tools:run /t/wrap.php'; }
php_scratch() { # <código PHP> → ejecuta en el contexto de AtoM sobre la BD scratch (preparación de casos)
  printf '<?php\nQubitSearch::disable();\n%s\n' "$1" >"$TMP/prep.php"
  in_scratch -- bash -c 'cd /atom/src && php symfony tools:run /t/prep.php'
  ((RC == 0)) || { echo "preparación fallida: $OUT"; exit 1; }
}
# Fragmentos PHP de preparación.
new_term() { # <taxonomía> <es> <en|''> [code] → crea un término ajeno (como lo haría un usuario o una importación)
  echo "\$t = new QubitTerm(); \$t->taxonomyId = $1; \$t->parentId = QubitTerm::ROOT_ID; \$t->sourceCulture = 'es';
    \$t->setName('$2', ['culture' => 'es']); if ('' !== '$3') { \$t->setName('$3', ['culture' => 'en']); }
    if ('' !== '${4:-}') { \$t->code = '${4:-}'; } \$t->save();"
}
del_code() { echo "foreach (QubitTerm::get((new Criteria())->add(QubitTerm::CODE, '$1')) as \$t) { \$t->delete(); }"; }
del_name() { echo "foreach (QubitPdo::fetchAll('SELECT t.id FROM term t JOIN term_i18n i ON i.id = t.id WHERE i.name = ? COLLATE utf8mb4_bin AND t.code IS NULL', ['$1'], ['fetchMode' => PDO::FETCH_COLUMN]) as \$id) { QubitTerm::getById(\$id)->delete(); }"; }

echo "== preparación: BD scratch clonada de atom =="
"${COMPOSE[@]}" up -d --wait percona >/dev/null
DEV_FP_BEFORE="$(fp atom)"
admin -e "CREATE DATABASE \`$SCRATCH\`"; CREATED_DB=1
admin -e "CREATE USER '$USR'@'%' IDENTIFIED BY '$PW'"; CREATED_USER=1
admin -e "GRANT ALL PRIVILEGES ON \`$SCRATCH\`.* TO '$USR'@'%'"
"${COMPOSE[@]}" exec -T percona sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" atom 2>/dev/null | mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" $SCRATCH 2>/dev/null"
php_scratch "$(del_code $SEC) $(del_code $REM) $(del_code $DES)"
expect "scratch: estado fresh-like (ninguno de los tres términos)" "0 0 0" "$(count_code "$SCRATCH" $SEC) $(count_code "$SCRATCH" $REM) $(count_code "$SCRATCH" $DES)"

echo "== Contrato estático =="
CFG="$("${COMPOSE[@]}" config --format json)"
expect "reconcile monta el script de términos RO" "True" \
  "$(python3 -c "import json,sys; v=[x for x in json.load(sys.stdin)['services']['reconcile']['volumes'] if x['target']=='/project/scripts/reconcile-terms.php']; print(len(v)==1 and v[0].get('read_only') is True)" <<<"$CFG")"
expect "reconcile.sh ejecuta el paso de términos tras check_for_updates" "1" \
  "$(grep -A1 'reconcile-check-for-updates.php"$' config/atom/reconcile.sh | grep -c 'tools:run "$HERE/reconcile-terms.php"' || true)"
expect "el script no borra ni actualiza por SQL (escribe solo con QubitTerm)" "0" \
  "$(grep -vE '^\s*(\*|/\*|//)' config/atom/reconcile-terms.php | grep -ciE 'delete|update |insert ' || true)"

echo "== A. Fresh-like: faltan los tres → se crean =="
FOREIGN0="$(foreign_fp "$SCRATCH")"
reconcile_terms
expect "exit 0" "0" "$RC"
expect_out "verificó la postcondición" "OK: términos creados y verificados"
expect "Sección: taxonomía 34, bajo la raíz, cultura fuente es, es/en" "34|110|es|Sección|Section" "$(term "$SCRATCH" $SEC)"
expect "Remitente: taxonomía 40, bajo la raíz, cultura fuente es, es/en" "40|110|es|Remitente|Remitente" "$(term "$SCRATCH" $REM)"
expect "Destinatario: taxonomía 40, bajo la raíz, cultura fuente es, es/en" "40|110|es|Destinatario|Destinatario" "$(term "$SCRATCH" $DES)"
expect "términos ajenos intactos (id, taxonomía, code, padre, nombres)" "$FOREIGN0" "$(foreign_fp "$SCRATCH")"

echo "== B. Idempotencia =="
FP1="$(fp "$SCRATCH")"
reconcile_terms
expect "segundo run: exit 0" "0" "$RC"
expect_out "segundo run: sin escritura" "ya existían; sin escritura"
expect "segundo run: tablas idénticas (ni siquiera lft/rgt)" "$FP1" "$(fp "$SCRATCH")"
reconcile_terms
expect "tercer run: tablas idénticas" "$FP1" "$(fp "$SCRATCH")"

echo "== C. Términos ajenos: no se cambian, no se borran, no se renombran =="
php_scratch "$(new_term 34 Legajo File-unit) $(new_term 40 Firmante '') $(new_term 40 Testigo Witness otro-proyecto:testigo) $(del_code $REM)"
FOREIGN1="$(foreign_fp "$SCRATCH")"
reconcile_terms
expect "falta Remitente con ajenos presentes: exit 0" "0" "$RC"
expect "Remitente recreado" "40|110|es|Remitente|Remitente" "$(term "$SCRATCH" $REM)"
expect "ajenos (incluidos los recién creados y uno con code de otro dueño) intactos" "$FOREIGN1" "$(foreign_fp "$SCRATCH")"
expect "los términos estándar siguen ahí (Fondo, Serie, Creación)" "1 1 1" \
  "$(count_name "$SCRATCH" 34 Fondo) $(count_name "$SCRATCH" 34 Serie) $(count_name "$SCRATCH" 40 Creación)"

echo "== D. Conflicto: homónimo sin nuestro code → STOP sin escribir =="
php_scratch "$(del_code $SEC) $(del_code $DES) $(new_term 34 Sección '')"
FP2="$(fp "$SCRATCH")"
reconcile_terms
expect "'Sección' ajeno: exit 78" "78" "$RC"
expect_out "lo reporta como conflicto" "CONFLICTO archivo-historico:seccion"
expect "no se creó duplicado: un solo 'Sección' en la taxonomía" "1" "$(count_name "$SCRATCH" 34 Sección)"
expect "todo o nada: Destinatario (sin conflicto) tampoco se creó" "0" "$(count_code "$SCRATCH" $DES)"
expect "ninguna escritura" "$FP2" "$(fp "$SCRATCH")"
php_scratch "$(del_name Sección) $(new_term 34 seccion SECTION)"
FP2="$(fp "$SCRATCH")"
reconcile_terms
expect "homónimo sin acentos ni mayúsculas ('seccion'/'SECTION'): exit 78" "78" "$RC"
expect "ninguna escritura" "$FP2" "$(fp "$SCRATCH")"
php_scratch "$(del_name seccion) $(new_term 40 Sección Section $SEC)"
FP2="$(fp "$SCRATCH")"
reconcile_terms
expect "nuestro code en otra taxonomía: exit 78" "78" "$RC"
expect_out "lo reporta" "el code existe en otra taxonomía"
expect "ninguna escritura" "$FP2" "$(fp "$SCRATCH")"
php_scratch "$(del_code $SEC) $(new_term 34 Sección Section $SEC) $(new_term 34 Sección Section $SEC)"
FP2="$(fp "$SCRATCH")"
reconcile_terms
expect "nuestro code repetido: exit 78" "78" "$RC"
expect "ninguna escritura" "$FP2" "$(fp "$SCRATCH")"
php_scratch "$(del_code $SEC)"
reconcile_terms
expect "sin conflictos: converge (exit 0)" "0" "$RC"
expect "los tres existen una sola vez" "1 1 1" "$(count_code "$SCRATCH" $SEC) $(count_code "$SCRATCH" $REM) $(count_code "$SCRATCH" $DES)"

echo "== E. Deriva de NUESTRO término → STOP sin escribir; homónimo ajeno posterior → solo aviso =="
# drift_case <descripción> <code> <SQL que altera> <SQL que restaura> <regex del motivo> <estado alterado esperado (term)>
drift_case() {
  admin "$SCRATCH" -e "$3"
  local fp_before
  fp_before="$(fp "$SCRATCH")"
  reconcile_terms
  expect "deriva ($1): exit 78" "78" "$RC"
  expect_out "deriva ($1): lo reporta como conflicto" "CONFLICTO $2 .*alterado: .*$5"
  expect "deriva ($1): no autocorrige (el valor alterado se conserva)" "$6" "$(term "$SCRATCH" "$2")"
  expect "deriva ($1): ninguna escritura" "$fp_before" "$(fp "$SCRATCH")"
  admin "$SCRATCH" -e "$4"
}
name_sql() { echo "UPDATE term_i18n i JOIN term t ON t.id = i.id SET i.name = '$3' WHERE t.code = '$1' AND i.culture = '$2'"; }
drift_case "nombre es" $SEC "$(name_sql $SEC es 'Sección renombrada')" "$(name_sql $SEC es Sección)" "nombre es 'Sección renombrada'" "34|110|es|Sección renombrada|Section"
drift_case "nombre en" $REM "$(name_sql $REM en Sender)" "$(name_sql $REM en Remitente)" "nombre en 'Sender'" "40|110|es|Remitente|Sender"
drift_case "padre" $DES "UPDATE term SET parent_id = $CREATION_ID WHERE code = '$DES'" "UPDATE term SET parent_id = 110 WHERE code = '$DES'" "padre $CREATION_ID" "40|$CREATION_ID|es|Destinatario|Destinatario"
drift_case "cultura fuente" $SEC "UPDATE term SET source_culture = 'en' WHERE code = '$SEC'" "UPDATE term SET source_culture = 'es' WHERE code = '$SEC'" "cultura fuente 'en'" "34|110|en|Sección|Section"
FP3="$(fp "$SCRATCH")"
reconcile_terms
expect "restaurado el estado canónico: exit 0 sin escritura" "0 1" "$RC $(grep -c 'ya existían; sin escritura' <<<"$OUT" || true)"
expect "restaurado el estado canónico: ninguna escritura" "$FP3" "$(fp "$SCRATCH")"

php_scratch "$(new_term 40 remitente '')"
FP3="$(fp "$SCRATCH")"
reconcile_terms
expect "homónimo ajeno posterior: exit 0" "0" "$RC"
expect_out "homónimo ajeno posterior: solo AVISO" "AVISO archivo-historico:remitente .*homónimo"
expect "homónimo ajeno posterior: ninguna escritura" "$FP3" "$(fp "$SCRATCH")"

expect "la BD real no cambió durante los casos scratch" "$DEV_FP_BEFORE" "$(fp atom)"

echo "== F. Happy path sobre la DEV real (up normal) =="
HAPPY_RC=0
"${COMPOSE[@]}" up -d --wait >"$TMP/up.log" 2>&1 || HAPPY_RC=$?
expect "up -d --wait completa con éxito" "0" "$HAPPY_RC"
REC="$("${COMPOSE[@]}" ps -aq reconcile)"
expect "reconcile terminó con 0" "0" "$(docker inspect -f '{{.State.ExitCode}}' "$REC")"
expect "los tres términos existen en la BD real" "34|110|es|Sección|Section 40|110|es|Remitente|Remitente 40|110|es|Destinatario|Destinatario" \
  "$(term atom $SEC) $(term atom $REM) $(term atom $DES)"
FP_DEV="$(fp atom)"
"${COMPOSE[@]}" run --rm --no-deps -T reconcile >"$TMP/rec.log" 2>&1 || true
expect "reconcile repetido sobre la DEV real: sin escritura" "1" "$(grep -c 'ya existían; sin escritura' "$TMP/rec.log" || true)"
expect "tablas de términos de la BD real idénticas" "$FP_DEV" "$(fp atom)"

echo
if ((fails)); then echo "test-reconcile-terms: $fails fallo(s)"; exit 1; fi
echo "test-reconcile-terms: OK"
