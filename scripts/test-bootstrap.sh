#!/usr/bin/env bash
# Prueba de integración de scripts/bootstrap.sh contra el Compose DEV (Percona + Elasticsearch + Memcached).
#
#   scripts/test-bootstrap.sh
#
# Como test-db-probe.sh: todo el estado destructivo (BDs, usuario, índices de Elasticsearch) tiene
# nombres con un id aleatorio, propios de esta ejecución; se crean sin IF NOT EXISTS y el cleanup
# elimina únicamente lo que esta ejecución creó. Ni la base `atom` ni el índice `atom` se tocan.
# El bootstrap se ejecuta con el servicio `bootstrap` del Compose (imagen archivo-historico/atom:2.10.2).
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose)
ROOT_PW="${MYSQL_ROOT_PASSWORD:-my-secret-pw}"
# Sin "_" en los nombres: en GRANT, "_" de un nombre de base es un comodín (y ES pide minúsculas).
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
USR="pu$RUN"
LATE_USR="pv$RUN"
PW="pw$RUN"
KEYS=(inst unk mis nores postfail sim70 cfg unreach late noadmin notable part chk)
declare -A DB
for k in "${KEYS[@]}"; do DB[$k]="pt$RUN$k"; done
CREATED_DBS=()
CREATED_USERS=()
CREATED_INDICES=()
fails=0
OUT=
RC=0

admin() { "${COMPOSE[@]}" exec -T percona mysql -uroot -p"$ROOT_PW" --batch --skip-column-names "$@" 2>/dev/null; }
es() { "${COMPOSE[@]}" exec -T elasticsearch curl -s -o /dev/null -w '%{http_code}' "$@"; }
# AtoM crea varios índices con el prefijo <índice>_ (p. ej. <índice>_qubitactor).
es_indices() { "${COMPOSE[@]}" exec -T elasticsearch curl -s "http://127.0.0.1:9200/_cat/indices/$1_*?h=index,creation.date&s=index"; }
es_index_count() { es_indices "$1" | grep -c . || true; }

cleanup() {
  local d i u
  for d in ${CREATED_DBS[@]+"${CREATED_DBS[@]}"}; do admin -e "DROP DATABASE \`$d\`" || true; done
  CREATED_DBS=()
  for u in ${CREATED_USERS[@]+"${CREATED_USERS[@]}"}; do admin -e "DROP USER '$u'@'%'" || true; done
  CREATED_USERS=()
  for i in ${CREATED_INDICES[@]+"${CREATED_INDICES[@]}"}; do es -XDELETE "http://127.0.0.1:9200/${i}_*" >/dev/null || true; done
  CREATED_INDICES=()
}
trap cleanup EXIT

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:70}"; else echo "FAIL  $1 -> ${3:0:600} (esperado ${2:0:600})"; fails=$((fails + 1)); fi
}
expect_out() { # <descripción> <regex que debe aparecer en $OUT>
  if grep -Eq "$2" <<<"$OUT"; then echo "PASS  $1"; else echo "FAIL  $1 (no aparece /$2/)"; fails=$((fails + 1)); fi
}
expect_no_out() { # <descripción> <regex que NO debe aparecer en $OUT>
  if grep -Eq "$2" <<<"$OUT"; then echo "FAIL  $1 (aparece /$2/)"; fails=$((fails + 1)); else echo "PASS  $1"; fi
}

# bootstrap <clave> [-e VAR=valor ...] [-- comando...]  → deja la salida en $OUT y el exit en $RC
bootstrap() {
  local k=$1; shift
  local opts=() cmd=()
  while (($#)); do
    if [[ $1 == -- ]]; then shift; cmd=("$@"); break; fi
    opts+=("$1"); shift
  done
  RC=0
  OUT=$("${COMPOSE[@]}" run --rm --no-deps -T \
    -e ATOM_MYSQL_DSN="mysql:host=percona;port=3306;dbname=${DB[$k]};charset=utf8mb4" \
    -e ATOM_MYSQL_USERNAME="$USR" -e ATOM_MYSQL_PASSWORD="$PW" -e ATOM_SEARCH_INDEX="${DB[$k]}" \
    -e BOOTSTRAP_WAIT_ATTEMPTS=3 -e BOOTSTRAP_WAIT_INTERVAL=1 \
    ${opts[@]+"${opts[@]}"} bootstrap ${cmd[@]+"${cmd[@]}"} 2>&1) || RC=$?
}
# Igual, pero carga bootstrap.sh como librería y ejecuta un fragmento antes de main (redefine pasos).
bootstrap_seam() { local k=$1 snippet=$2; shift 2; bootstrap "$k" "$@" -- bash -c "source /project/scripts/bootstrap.sh; $snippet; main"; }

# mini_schema <db> <version> [admin|empty|nonadmin|none]: estructura mínima de AtoM (subconjunto del DDL real);
# el 3.º argumento define acl_user_group: con admin (grupo 100), vacía, con solo otro grupo, o ausente (por defecto).
mini_schema() {
  admin "$1" -e "
    CREATE TABLE object (id INT AUTO_INCREMENT PRIMARY KEY, class_name VARCHAR(255));
    CREATE TABLE setting (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), scope VARCHAR(255), source_culture VARCHAR(16) NOT NULL);
    CREATE TABLE setting_i18n (id INT NOT NULL, culture VARCHAR(16) NOT NULL, value TEXT, PRIMARY KEY (id, culture));
    CREATE TABLE user (id INT PRIMARY KEY, username VARCHAR(255));
    INSERT INTO setting (name, source_culture) VALUES ('version','en');
    INSERT INTO setting_i18n (id, culture, value) SELECT id, 'en', '$2' FROM setting WHERE name='version';"
  case "${3:-none}" in
    admin) admin "$1" -e "CREATE TABLE acl_user_group (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, group_id INT NOT NULL); INSERT INTO acl_user_group (user_id, group_id) VALUES (1, 100)" ;;
    empty) admin "$1" -e "CREATE TABLE acl_user_group (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, group_id INT NOT NULL)" ;;
    nonadmin) admin "$1" -e "CREATE TABLE acl_user_group (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, group_id INT NOT NULL); INSERT INTO acl_user_group (user_id, group_id) VALUES (1, 99), (1, 101)" ;;
  esac
}

counters() { admin -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Com_insert','Com_update','Com_delete','Com_replace','Com_create_table','Com_drop_table','Com_alter_table','Com_truncate','Com_create_db','Com_drop_db')"; }
# Huella de contenido de TODAS las tablas base de <db> (nombre + CHECKSUM TABLE, solo lectura, determinista).
# Detecta cualquier cambio de datos aunque el nº de tablas no cambie; BD sin tablas → "sin tablas".
db_fingerprint() { # <db>
  local tables
  tables="$(admin -e "SET SESSION group_concat_max_len = 1048576; SELECT GROUP_CONCAT(CONCAT('\`', table_schema, '\`.\`', table_name, '\`') ORDER BY table_name) FROM information_schema.tables WHERE table_schema='$1' AND table_type='BASE TABLE'")"
  if [[ -z "$tables" || "$tables" == NULL ]]; then echo "sin tablas"; else admin -e "CHECKSUM TABLE $tables"; fi
}
db_snapshot() { # <db> <tabla a checksumear>
  admin -e "SELECT table_name, table_rows, create_time, update_time FROM information_schema.tables WHERE table_schema='$1' ORDER BY 1; CHECKSUM TABLE \`$1\`.\`$2\`"
}
table_count() { admin -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$1'"; }

echo "== preparación =="
"${COMPOSE[@]}" up -d --wait percona elasticsearch memcached >/dev/null
# Sin cleanup inicial: los nombres son nuevos y CREATE falla si existieran.
for k in "${KEYS[@]}"; do admin -e "CREATE DATABASE \`${DB[$k]}\`"; CREATED_DBS+=("${DB[$k]}"); done
admin -e "CREATE USER '$USR'@'%' IDENTIFIED BY '$PW'"; CREATED_USERS+=("$USR")
for k in "${KEYS[@]}"; do admin -e "GRANT ALL PRIVILEGES ON \`${DB[$k]}\`.* TO '$USR'@'%'"; CREATED_INDICES+=("${DB[$k]}"); done
admin "${DB[unk]}" -e "CREATE TABLE cualquier_cosa (id INT PRIMARY KEY); INSERT INTO cualquier_cosa VALUES (1)"
mini_schema "${DB[mis]}" 196
mini_schema "${DB[late]}" 197 admin
mini_schema "${DB[noadmin]}" 197 nonadmin
mini_schema "${DB[notable]}" 197
mini_schema "${DB[chk]}" 197 admin

# La base/índice `atom` pueden estar vacíos o ya instalados (runtime DEV): solo se exige que esta prueba no los altere.
ATOM_FINGERPRINT_BEFORE="$(db_fingerprint atom)"
ATOM_INDICES_BEFORE="$(es_index_count atom)"

echo "== A. FRESH → install → post-probe =="
bootstrap inst
expect "A exit 0" 0 "$RC"
expect_out "A probe inicial FRESH" 'probe: DB_FRESH \(exit 10\)'
expect_out "A ejecuta tools:install" 'ejecutando tools:install'
expect_out "A post-probe COMPATIBLE" 'post-probe: DB_COMPATIBLE'
expect_out "A installation-check completa" 'instalación completa verificada'
expect_out "A éxito declarado solo al final" 'bootstrap: instalación verificada'
expect_no_out "A no filtra contraseñas en el log" '(Database|Admin) password'
expect "A la BD quedó con estructura AtoM y usuario admin" "1" "$(admin -e "SELECT COUNT(*) FROM \`${DB[inst]}\`.user WHERE username='admin'")"
expect "A índices ES creados" "1" "$([[ $(es_index_count "${DB[inst]}") -ge 1 ]] && echo 1 || echo 0)"
admin -e "INSERT INTO \`${DB[inst]}\`.setting (name, source_culture) VALUES ('wu5_marker','en')"

echo "== B. segunda ejecución: COMPATIBLE, sin reinstalar =="
before_c=$(counters); before_s=$(db_snapshot "${DB[inst]}" setting); before_es=$(es_indices "${DB[inst]}")
bootstrap inst
expect "B exit 0" 0 "$RC"
expect_out "B probe COMPATIBLE" 'probe: DB_COMPATIBLE \(exit 0\)'
expect_out "B no instala" 'BD compatible; no se instala'
expect_out "B installation-check completa" 'instalación completa verificada'
expect_no_out "B no ejecuta tools:install" 'ejecutando tools:install|Installation completed'
expect "B contadores de escritura del servidor" "$before_c" "$(counters)"
expect "B tablas/create_time/filas y marcador intactos" "$before_s" "$(db_snapshot "${DB[inst]}" setting)"
expect "B índices ES no recreados (creation.date)" "$before_es" "$(es_indices "${DB[inst]}")"

echo "== C. UNKNOWN → STOP =="
before_c=$(counters); before_s=$(db_snapshot "${DB[unk]}" cualquier_cosa)
bootstrap unk
expect "C exit 20" 20 "$RC"
expect_out "C STOP sin instalar" 'probe: DB_UNKNOWN \(exit 20\)'
expect_no_out "C no ejecuta tools:install" 'ejecutando tools:install'
expect "C contadores de escritura del servidor" "$before_c" "$(counters)"
expect "C estado intacto" "$before_s" "$(db_snapshot "${DB[unk]}" cualquier_cosa)"
expect "C índices ES no creados" "0" "$(es_index_count "${DB[unk]}")"

echo "== D. SCHEMA_MISMATCH → STOP =="
before_c=$(counters); before_s=$(db_snapshot "${DB[mis]}" setting)
bootstrap mis
expect "D exit 21" 21 "$RC"
expect_out "D STOP sin install/upgrade" 'probe: DB_SCHEMA_MISMATCH \(exit 21\)'
expect_no_out "D no ejecuta tools:install ni upgrade" 'ejecutando tools:install|upgrade-sql'
expect "D contadores de escritura del servidor" "$before_c" "$(counters)"
expect "D estado intacto" "$before_s" "$(db_snapshot "${DB[mis]}" setting)"
expect "D índices ES no creados" "0" "$(es_index_count "${DB[mis]}")"

echo "== E. UNREACHABLE → reintento acotado y fallo =="
start=$SECONDS
bootstrap unreach -e ATOM_MYSQL_DSN='mysql:host=percona;port=1;dbname=x;charset=utf8mb4'
elapsed=$((SECONDS - start))
expect "E exit 30" 30 "$RC"
expect_out "E reintenta (2 esperas de 3 intentos)" 'intento 2/3'
expect_no_out "E no supera el máximo de intentos" 'intento 3/3\); reintento'
expect_no_out "E no instala" 'ejecutando tools:install'
echo "      (duración con 3 intentos × 1s: ${elapsed}s incl. arranque del contenedor)"
echo "-- E2. recuperación: el usuario aparece durante la espera (BD ya poblada: sin carrera con el probe) --"
CREATED_USERS+=("$LATE_USR")
( sleep 10; admin -e "CREATE USER '$LATE_USR'@'%' IDENTIFIED BY '$PW'; GRANT SELECT ON \`${DB[late]}\`.* TO '$LATE_USR'@'%'" ) &
bootstrap late -e ATOM_MYSQL_USERNAME="$LATE_USR" -e BOOTSTRAP_WAIT_ATTEMPTS=30
wait
expect "E2 exit 0" 0 "$RC"
expect_out "E2 hubo reintentos" 'reintento en 1s'
expect_out "E2 termina COMPATIBLE sin instalar" 'BD compatible; no se instala'
expect_out "E2 installation-check completa" 'instalación completa verificada'

echo "== dependencias: Elasticsearch / Memcached no disponibles con BD FRESH =="
before_c=$(counters)
bootstrap nores -e ATOM_ELASTICSEARCH_HOST=no-existe.invalid:9200
expect "ES exit 42" 42 "$RC"
expect_out "ES espera acotada" 'esperando Elasticsearch .* \(intento 2/3\)'
expect_no_out "ES no ejecuta tools:install" 'ejecutando tools:install'
expect "ES la BD sigue vacía" 0 "$(table_count "${DB[nores]}")"
bootstrap nores -e ATOM_MEMCACHED_HOST=no-existe.invalid:11211
expect "Memcached exit 42" 42 "$RC"
expect_out "Memcached espera acotada" 'esperando Memcached .* \(intento 2/3\)'
expect_no_out "Memcached no ejecuta tools:install" 'ejecutando tools:install'
expect "Memcached la BD sigue vacía" 0 "$(table_count "${DB[nores]}")"
expect "dependencias: contadores de escritura del servidor" "$before_c" "$(counters)"

echo "== F. error de configuración / probe =="
before_c=$(counters)
bootstrap cfg -e ATOM_MYSQL_DSN='mysql:host=percona;port=3306;charset=utf8mb4'
expect "F1 DSN sin dbname → exit 64" 64 "$RC"
expect_no_out "F1 no instala" 'ejecutando tools:install'
expect "F1 la BD sigue vacía" 0 "$(table_count "${DB[cfg]}")"
# Simulaciones (redefinen pasos del script cargado como librería; el flujo real no cambia):
SNIP_INSTALL_MARK='run_install() { echo INSTALL-INVOCADO; }'
bootstrap_seam sim70 "$SNIP_INSTALL_MARK; run_probe() { PROBE_RC=70; PROBE_STATE=; }"
expect "F2 probe con exit 70 (simulado) → exit 70" 70 "$RC"
expect_no_out "F2 no instala" 'INSTALL-INVOCADO'
bootstrap_seam sim70 "$SNIP_INSTALL_MARK; run_probe() { PROBE_RC=64; PROBE_STATE=; }"
expect "F3 probe con exit 64 (simulado) → exit 64" 64 "$RC"
expect_no_out "F3 no instala" 'INSTALL-INVOCADO'
bootstrap_seam sim70 "$SNIP_INSTALL_MARK; run_probe() { PROBE_RC=99; PROBE_STATE=; }"
expect "F4 exit desconocido del probe (simulado) → exit 70" 70 "$RC"
expect_no_out "F4 no instala" 'INSTALL-INVOCADO'
expect "F contadores de escritura del servidor" "$before_c" "$(counters)"

echo "== G. el post-probe es requisito real =="
# install "exitoso" (exit 0) que no deja la BD compatible: el bootstrap no puede declarar éxito.
bootstrap_seam postfail "run_install() { echo 'install simulado: exit 0 sin cambios'; }"
expect "G exit 41" 41 "$RC"
expect_out "G el install (simulado) sí se ejecutó" 'install simulado'
expect_out "G el post-probe no fue COMPATIBLE" 'instalación NO verificada: el probe posterior dio DB_FRESH'
expect_no_out "G no declara éxito" 'instalación verificada'
# install que falla: exit 40.
bootstrap_seam postfail "run_install() { return 3; }"
expect "G2 install que falla → exit 40" 40 "$RC"

echo "== installation-check: COMPATIBLE sintético sin administrador (grupo 100) =="
for k in noadmin notable; do
  before_c=$(counters); before_s=$(db_snapshot "${DB[$k]}" setting)
  bootstrap "$k"
  expect "I-$k exit 43" 43 "$RC"
  expect_out "I-$k el probe dice COMPATIBLE" 'probe: DB_COMPATIBLE \(exit 0\)'
  expect_out "I-$k reporta instalación incompleta" 'instalación INCOMPLETA'
  expect_no_out "I-$k no instala ni actualiza" 'ejecutando tools:install|upgrade-sql|Installation completed'
  expect_no_out "I-$k no declara éxito" 'instalación verificada'
  expect "I-$k contadores de escritura del servidor" "$before_c" "$(counters)"
  expect "I-$k estado intacto" "$before_s" "$(db_snapshot "${DB[$k]}" setting)"
  expect "I-$k índices ES no creados" "0" "$(es_index_count "${DB[$k]}")"
done
echo "-- installation-check directo (invariante y consulta read-only) --"
check() { # <clave> [-e VAR=valor ...] → "estado exit"
  local k=$1 rc=0 out; shift
  out=$("${COMPOSE[@]}" run --rm --no-deps -T \
    -e ATOM_MYSQL_DSN="mysql:host=percona;port=3306;dbname=${DB[$k]};charset=utf8mb4" \
    -e ATOM_MYSQL_USERNAME="$USR" -e ATOM_MYSQL_PASSWORD="$PW" \
    ${@+"$@"} --entrypoint php bootstrap /project/scripts/installation-check.php 2>/dev/null) || rc=$?
  echo "${out:-<vacío>} $rc"
}
expect "check: admin en grupo 100"        "INSTALL_COMPLETE 0"   "$(check chk)"
expect "check: solo grupos 99/101"        "INSTALL_INCOMPLETE 1" "$(check noadmin)"
expect "check: acl_user_group ausente"    "INSTALL_INCOMPLETE 1" "$(check notable)"
expect "check: BD inalcanzable → 70"      "<vacío> 70"           "$(check chk -e ATOM_MYSQL_DSN='mysql:host=percona;port=1;dbname=x')"
expect "check: DSN sin dbname → 64"       "<vacío> 64"           "$(check chk -e ATOM_MYSQL_DSN='mysql:host=percona;port=3306;charset=utf8mb4')"

echo "== instalación parcial real (memory_limit 512M) =="
bootstrap part -e ATOM_PHP_MEMORY_LIMIT=512M
expect "P1 el install interrumpido termina en 40" 40 "$RC"
expect_out "P1 tools:install se ejecutó" 'ejecutando tools:install'
expect_no_out "P1 no declara éxito" 'instalación verificada'
before_c=$(counters); before_s=$(db_snapshot "${DB[part]}" setting)
expect "P1 la BD parcial tiene version 197 y sin administrador" "197 0" "$(admin -e "SELECT (SELECT si.value FROM \`${DB[part]}\`.setting s JOIN \`${DB[part]}\`.setting_i18n si ON si.id=s.id WHERE s.name='version'), (SELECT COUNT(*) FROM \`${DB[part]}\`.acl_user_group WHERE group_id=100)" | tr '\t' ' ')"
bootstrap part
expect "P2 nueva ejecución sobre la misma BD → exit 43" 43 "$RC"
expect_out "P2 el probe la ve COMPATIBLE" 'probe: DB_COMPATIBLE \(exit 0\)'
expect_out "P2 installation-check incompleta" 'instalación INCOMPLETA'
expect_no_out "P2 nunca reintenta el install" 'ejecutando tools:install|Installation completed|upgrade-sql'
expect_no_out "P2 no declara éxito" 'instalación verificada'
expect "P2 contadores de escritura del servidor" "$before_c" "$(counters)"
expect "P2 estado intacto" "$before_s" "$(db_snapshot "${DB[part]}" setting)"

echo "== G3. post-probe simulado COMPATIBLE pero installation-check incompleto =="
# Install y post-probe simulados (FRESH y luego COMPATIBLE); el installation-check es el real y ve una BD vacía.
bootstrap_seam postfail "N=0; run_install() { echo 'install simulado'; }; run_probe() { N=\$((N+1)); if ((N == 1)); then PROBE_RC=10; PROBE_STATE=DB_FRESH; else PROBE_RC=0; PROBE_STATE=DB_COMPATIBLE; fi; }"
expect "G3 exit 43" 43 "$RC"
expect_out "G3 el post-probe fue COMPATIBLE" 'post-probe: DB_COMPATIBLE'
expect_out "G3 el installation-check lo rechaza" 'instalación INCOMPLETA'
expect_no_out "G3 no declara éxito" 'instalación verificada'

echo "== limpieza =="
cleanup
leftover_dbs=$(admin -e "SHOW DATABASES LIKE 'pt${RUN}%'" | wc -l)
leftover_users=$(admin -e "SELECT user FROM mysql.user WHERE user IN ('$USR','$LATE_USR')" | wc -l)
leftover_idx=$("${COMPOSE[@]}" exec -T elasticsearch curl -s "http://127.0.0.1:9200/_cat/indices/pt${RUN}*?h=index" | wc -l)
expect "BDs / usuarios / índices ES de esta ejecución tras cleanup" "0 0 0" "$leftover_dbs $leftover_users $leftover_idx"
expect "base atom intacta (huella de contenido de todas las tablas)" "$ATOM_FINGERPRINT_BEFORE" "$(db_fingerprint atom)"
expect "índices ES 'atom_*' sin cambios" "$ATOM_INDICES_BEFORE" "$(es_index_count atom)"

echo
if ((fails)); then echo "$fails fallo(s)"; exit 1; fi
echo "OK"
