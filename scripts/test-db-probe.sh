#!/usr/bin/env bash
# Prueba de integración del detector scripts/db-probe.php contra el Percona del Compose DEV.
#
#   scripts/test-db-probe.sh
#
# Los fixtures se crean desde fuera del probe (root) en bases y un usuario que pertenecen solo a
# esta ejecución (nombres con un id aleatorio, creados sin IF NOT EXISTS: si ya existieran, la
# prueba aborta). El cleanup elimina únicamente lo que esta ejecución creó; la base `atom` no se
# toca. El probe se ejecuta con un usuario que solo tiene SELECT sobre esas bases concretas.
# Requiere la imagen archivo-historico/atom:2.10.2 (su PHP/PDO).
set -euo pipefail

cd "$(dirname "$0")/.."
# --progress quiet: `run` re-verifica el grafo de build (additional_contexts: atom_upstream) en cada
# invocación; sin TTY ese trazo (aunque cacheado) sale por stdout y contamina probe() de abajo, que compara
# la salida de `db-probe` capturada contra un valor exacto. Reproducido en WSL/Linux, no es un workaround de
# Git Bash/MSYS.
COMPOSE=(docker compose --progress quiet)
ROOT_PW="${MYSQL_ROOT_PASSWORD:-my-secret-pw}"
# Sin "_" en los nombres: en GRANT, "_" de un nombre de base es un comodín.
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
RO_USER="pu$RUN"
RO_PW="pw$RUN"
DB_NAMES=(fresh unknown v196 v197 v198 real noversion badcols nonnumeric)
declare -A DB
for n in "${DB_NAMES[@]}"; do DB[$n]="pt$RUN$n"; done
CREATED_DBS=()
CREATED_USER=
fails=0

admin() { "${COMPOSE[@]}" exec -T percona mysql -uroot -p"$ROOT_PW" --batch --skip-column-names "$@" 2>/dev/null; }

# Elimina solo lo registrado como creado por esta ejecución; idempotente.
cleanup() {
  local d
  for d in ${CREATED_DBS[@]+"${CREATED_DBS[@]}"}; do admin -e "DROP DATABASE \`$d\`" || true; done
  CREATED_DBS=()
  if [[ -n "$CREATED_USER" ]]; then admin -e "DROP USER '$CREATED_USER'@'%'" || true; CREATED_USER=; fi
}
trap cleanup EXIT

probe() { # <clave de DB> [ENV=valor ...] -> imprime "estado exit"
  local db=$1; shift
  local out rc=0
  out=$("${COMPOSE[@]}" run --rm --no-deps -T \
    -e ATOM_MYSQL_DSN="mysql:host=percona;port=3306;dbname=${DB[$db]};charset=utf8mb4" \
    -e ATOM_MYSQL_USERNAME="$RO_USER" -e ATOM_MYSQL_PASSWORD="$RO_PW" \
    "${@/#/-e}" db-probe 2>/dev/null) || rc=$?
  echo "${out:-<vacío>} $rc"
}

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:60}"; else echo "FAIL  $1 -> ${3:0:600} (esperado ${2:0:600})"; fails=$((fails + 1)); fi
}

mini_schema() { # <db> <version|''>  Estructura mínima de AtoM (subconjunto del DDL real).
  admin "$1" -e "
    CREATE TABLE object (id INT AUTO_INCREMENT PRIMARY KEY, class_name VARCHAR(255));
    CREATE TABLE setting (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), scope VARCHAR(255), source_culture VARCHAR(16) NOT NULL);
    CREATE TABLE setting_i18n (id INT NOT NULL, culture VARCHAR(16) NOT NULL, value TEXT, PRIMARY KEY (id, culture));
    CREATE TABLE user (id INT PRIMARY KEY, username VARCHAR(255));"
  if [[ -n "$2" ]]; then
    admin "$1" -e "INSERT INTO setting (name, source_culture) VALUES ('version','en');
                   INSERT INTO setting_i18n (id, culture, value) SELECT id, 'en', '$2' FROM setting WHERE name='version';"
  fi
}

# La base `atom` puede estar vacía o ya instalada (runtime DEV): solo se exige que esta prueba no la altere.
atom_tables() { admin -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='atom'"; }
ATOM_TABLES_BEFORE="$(atom_tables)"

echo "== preparación =="
"${COMPOSE[@]}" up -d --wait percona >/dev/null
# El schema esperado del probe debe coincidir con la última migración de upstream.
latest=$(ls upstream/atom/lib/task/migrate/migrations/ | sed -n 's/^arMigration0*\([0-9]\+\)\.class\.php$/\1/p' | sort -n | tail -1)
expect "EXPECTED_SCHEMA == última arMigration de upstream" "$latest" "$(sed -n 's/^const EXPECTED_SCHEMA = \([0-9]*\);/\1/p' scripts/db-probe.php)"

# Sin cleanup inicial: los nombres son nuevos y CREATE falla si existieran.
for n in "${DB_NAMES[@]}"; do admin -e "CREATE DATABASE \`${DB[$n]}\`"; CREATED_DBS+=("${DB[$n]}"); done
admin -e "CREATE USER '$RO_USER'@'%' IDENTIFIED BY '$RO_PW'"; CREATED_USER=$RO_USER
for n in "${DB_NAMES[@]}"; do admin -e "GRANT SELECT ON \`${DB[$n]}\`.* TO '$RO_USER'@'%'"; done

admin "${DB[unknown]}" -e "CREATE TABLE cualquier_cosa (id INT PRIMARY KEY)"
mini_schema "${DB[v196]}" 196
mini_schema "${DB[v197]}" 197
mini_schema "${DB[v198]}" 198
mini_schema "${DB[noversion]}" ''
admin "${DB[badcols]}" -e "CREATE TABLE object (x INT); CREATE TABLE setting (x INT); CREATE TABLE setting_i18n (x INT); CREATE TABLE user (x INT)"
mini_schema "${DB[nonnumeric]}" abc
# DDL real de AtoM (el que carga tools:install, sin ejecutar la tarea) + versión 197.
"${COMPOSE[@]}" exec -T percona mysql -uroot -p"$ROOT_PW" "${DB[real]}" 2>/dev/null <upstream/atom/data/sql/lib.model.schema.sql
admin "${DB[real]}" -e "INSERT INTO setting (name, source_culture) VALUES ('version','en');
                        INSERT INTO setting_i18n (id, culture, value) SELECT id, 'en', '197' FROM setting WHERE name='version';"

write_counters() { admin -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Com_insert','Com_update','Com_delete','Com_replace','Com_create_table','Com_drop_table','Com_alter_table','Com_truncate','Com_create_db','Com_drop_db')"; }
snapshot() {
  local in_list; in_list=$(printf "'%s'," "${DB[@]}"); in_list=${in_list%,}
  admin -e "SELECT table_schema, table_name, table_rows, create_time, update_time FROM information_schema.tables WHERE table_schema IN ($in_list) ORDER BY 1,2; SELECT MD5(GROUP_CONCAT(value)) FROM \`${DB[v197]}\`.setting_i18n"
}
before_c=$(write_counters); before_s=$(snapshot)

echo "== estados =="
expect "BD vacía"                          "DB_FRESH 10"           "$(probe fresh)"
expect "BD no vacía no-AtoM"               "DB_UNKNOWN 20"         "$(probe unknown)"
expect "AtoM + schema 196"                 "DB_SCHEMA_MISMATCH 21" "$(probe v196)"
expect "AtoM + schema 197"                 "DB_COMPATIBLE 0"       "$(probe v197)"
expect "endpoint no alcanzable (puerto)"   "DB_UNREACHABLE 30"     "$(probe v197 ATOM_MYSQL_DSN='mysql:host=percona;port=1;dbname=x')"
echo "== bordes =="
expect "AtoM + schema 198 (más nuevo)"     "DB_SCHEMA_MISMATCH 21" "$(probe v198)"
expect "DDL real AtoM + schema 197"        "DB_COMPATIBLE 0"       "$(probe real)"
expect "4 tablas sin setting version"      "DB_UNKNOWN 20"         "$(probe noversion)"
expect "4 tablas con columnas distintas"   "DB_UNKNOWN 20"         "$(probe badcols)"
expect "version no numérica"               "DB_UNKNOWN 20"         "$(probe nonnumeric)"
expect "host inexistente"                  "DB_UNREACHABLE 30"     "$(probe v197 ATOM_MYSQL_DSN='mysql:host=no-existe.invalid;port=3306;dbname=x')"
expect "credenciales inválidas"            "DB_UNREACHABLE 30"     "$(probe v197 ATOM_MYSQL_PASSWORD=incorrecta)"
expect "DSN sin dbname"                    "<vacío> 64"            "$(probe v197 ATOM_MYSQL_DSN='mysql:host=percona;port=3306;charset=utf8mb4')"
expect "sin configuración"                 "<vacío> 64"            "$(probe v197 ATOM_MYSQL_DSN=)"

echo "== sin efectos secundarios =="
expect "contadores de escritura del servidor" "$before_c" "$(write_counters)"
expect "tablas/filas/timestamps de fixtures"  "$before_s" "$(snapshot)"

echo "== cleanup =="
cleanup
leftover_dbs=$(admin -e "SHOW DATABASES LIKE 'pt${RUN}%'" | wc -l)
leftover_users=$(admin -e "SELECT user FROM mysql.user WHERE user = '$RO_USER'" | wc -l)
expect "recursos de esta ejecución tras cleanup (BDs usuarios)" "0 0" "$leftover_dbs $leftover_users"
expect "base atom intacta (mismo nº de tablas)" "$ATOM_TABLES_BEFORE" "$(atom_tables)"

echo
if ((fails)); then echo "$fails fallo(s)"; exit 1; fi
echo "OK"
