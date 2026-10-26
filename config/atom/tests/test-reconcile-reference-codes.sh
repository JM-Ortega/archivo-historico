#!/usr/bin/env bash
# Prueba de integración del reconcile de `inherit_code_informationobject = 0` (config/atom/reconcile-reference-codes.php) contra el Compose DEV.
#
#   config/atom/tests/test-reconcile-strong-passwords.sh
#
# Aislamiento: los casos que alteran el setting corren sobre una BD SCRATCH clonada de `atom` (id aleatorio, usuario propio con permisos
# solo sobre ella), que se elimina al terminar. La caché de settings (Memcached) es compartida con la DEV; es derivada y la última
# sección la invalida con un reconcile real, así ningún valor scratch sobrevive. La observación es SQL directo, independiente del script.
# Las comprobaciones con descripciones de prueba (jerarquía Fondo > Serie > Subserie > Unidad con la Signatura `5640 (Ind. C II -24 mn)`)
# viven solo en la BD scratch y en un índice de Elasticsearch desechable (prefijo aleatorio `$INDEX_`, borrado al terminar): ni la BD ni
# el índice `atom_*` de la DEV reciben descripciones. La BD `atom` real solo se toca en la sección final (reconcile real); nunca `down -v`.
# Requiere el runtime arriba (`docker compose up -d --wait`).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
COMPOSE=(docker compose)
ROOT_PW="${MYSQL_ROOT_PASSWORD:-my-secret-pw}"
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
SCRATCH="rr$RUN" # sin "_": en GRANT, "_" de un nombre de base es un comodín
USR="ru$RUN"
PW="pw$RUN"
TMP="$(mktemp -d)"
fails=0
OUT=
RC=0
CREATED_DB=0
CREATED_USER=0
INDEX_="zz$RUN"

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
  "${COMPOSE[@]}" exec -T elasticsearch curl -s -XDELETE "localhost:9200/${INDEX_}_qubitinformationobject" >/dev/null 2>&1 || true
  rm -rf "$TMP"
}
trap cleanup EXIT

# --- observación independiente (SQL) ---
rsp() { # <db> → "source_culture/culture=valor" por fila i18n de inherit_code_informationobject
  admin "$1" -e "SELECT CONCAT_WS('/', s.source_culture, CONCAT(i.culture, '=', i.value)) FROM setting s JOIN setting_i18n i ON i.id = s.id WHERE s.name = 'inherit_code_informationobject' ORDER BY i.culture" | tr '\n' ' '
}
others() { # <db> → huella de TODAS las demás filas de settings (incluido check_for_updates)
  admin "$1" -e "SELECT CONCAT_WS('~', s.id, s.name, IFNULL(s.scope,''), s.source_culture, IFNULL(i.culture,''), IFNULL(i.value,'')) FROM setting s
    LEFT JOIN setting_i18n i ON i.id = s.id WHERE s.name <> 'inherit_code_informationobject' ORDER BY s.id, i.culture" | sha256sum | cut -c1-16
}
fp() { admin "$1" -e "CHECKSUM TABLE setting, setting_i18n" | tr -s '\t' ' ' | tr '\n' ';'; }

# --- ejecución contra la BD scratch ---
in_scratch() { # [opciones docker --] <comando...> → $OUT y $RC
  local opts=()
  if [[ "${1:-}" == -* ]]; then while [[ $1 != -- ]]; do opts+=("$1"); shift; done; shift; fi
  RC=0
  OUT=$("${COMPOSE[@]}" run --rm --no-deps -T ${opts[@]+"${opts[@]}"} \
    -e ATOM_MYSQL_DSN="mysql:host=percona;port=3306;dbname=$SCRATCH;charset=utf8mb4" \
    -e ATOM_MYSQL_USERNAME="$USR" -e ATOM_MYSQL_PASSWORD="$PW" \
    reconcile "$@" 2>&1) || RC=$?
}
reconcile_rsp() { in_scratch bash -c 'cd /atom/src && php symfony tools:run /project/scripts/reconcile-reference-codes.php'; }
php_scratch() { # <fichero PHP en $TMP> → ejecuta en el contexto de AtoM sobre la BD scratch ($OUT, $RC)
  in_scratch -v "$TMP:/t:ro" -e RC_INDEX="$INDEX_" -- bash -c "cd /atom/src && php symfony tools:run /t/$1"
  if ((RC != 0)); then echo "---- salida de $1 (exit $RC) ----"; echo "$OUT" | tail -15; fi
}
val() { grep -m1 "^$1=" <<<"$OUT" | cut -d= -f2-; }

echo "== preparación: BD scratch clonada de atom =="
"${COMPOSE[@]}" up -d --wait percona >/dev/null
DEV_FP_BEFORE="$(fp atom)"
admin -e "CREATE DATABASE \`$SCRATCH\`"; CREATED_DB=1
admin -e "CREATE USER '$USR'@'%' IDENTIFIED BY '$PW'"; CREATED_USER=1
admin -e "GRANT ALL PRIVILEGES ON \`$SCRATCH\`.* TO '$USR'@'%'"
"${COMPOSE[@]}" exec -T percona sh -c "mysqldump -uroot -p\"\$MYSQL_ROOT_PASSWORD\" atom 2>/dev/null | mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" $SCRATCH 2>/dev/null"
setv() { admin "$SCRATCH" -e "UPDATE setting_i18n i JOIN setting s ON s.id = i.id AND i.culture = s.source_culture SET i.value = '$1' WHERE s.name = 'inherit_code_informationobject'"; }

# --- descripciones de prueba (solo en la BD scratch) ---
cat >"$TMP/seed.php" <<'PHP'
<?php
// Fondo > Serie > Subserie > Unidad; la Signatura completa va en `identifier` de la unidad. Sin indexar (QubitSearch::disable()).
QubitSearch::disable();
$level = static fn (string $en) => QubitPdo::fetchColumn('SELECT t.id FROM term t JOIN term_i18n i ON i.id = t.id AND i.culture = "en" WHERE t.taxonomy_id = 34 AND i.name = ?', [$en]);
$repo = new QubitRepository();
$repo->parentId = QubitRepository::ROOT_ID;
$repo->identifier = 'AHU';
$repo->setAuthorizedFormOfName('Repositorio de prueba', ['culture' => 'es']);
$repo->save();
$mk = static function (int $parent, string $identifier, string $title, string $lod, ?int $repoId = null) use ($level): int {
    $io = new QubitInformationObject();
    $io->parentId = $parent;
    $io->identifier = $identifier;
    $io->levelOfDescriptionId = $level($lod);
    $io->sourceCulture = 'es';
    $io->setTitle($title, ['culture' => 'es']);
    if (null !== $repoId) {
        $io->repositoryId = $repoId;
    }
    $io->save();

    return $io->id;
};
$f = $mk(QubitInformationObject::ROOT_ID, 'AHC', 'Fondo de prueba', 'Fonds', $repo->id);
$s = $mk($f, 'C II', 'Serie de prueba', 'Series');
$ss = $mk($s, '24 mn', 'Subserie de prueba', 'Subseries');
$u = $mk($ss, '5640 (Ind. C II -24 mn)', 'Unidad de prueba', 'File');
echo "SEED=$f,$s,$ss,$u,{$repo->id}\n";
PHP
cat >"$TMP/inspect.php" <<'PHP'
<?php
// Qué ve cada consumidor de la Signatura con el ajuste vigente en la BD: ficha (sfIsadPlugin::referenceCode), EAD (<unitid>),
// documento indexado y búsquedas (índice desechable con prefijo RC_INDEX; nunca el índice atom_* de la DEV).
sfConfig::add(QubitSetting::getSettingsArray()); // lo que carga QubitSettingsFilter en una request
// El motor de búsqueda se crea después de fijar el prefijo: todo lo que indexe va al índice desechable.
arElasticSearchPluginConfiguration::$config['index']['name'] = getenv('RC_INDEX');
$sig = '5640 (Ind. C II -24 mn)';
$ids = QubitPdo::fetchAll('SELECT id, identifier FROM information_object WHERE identifier IN ("AHC", "C II", "24 mn", ?) ORDER BY lft', [$sig]);
$byIdent = [];
foreach ($ids as $r) {
    $byIdent[$r->identifier] = QubitInformationObject::getById($r->id);
}
$u = $byIdent[$sig];
echo 'SETTING=', var_export(sfConfig::get('app_inherit_code_informationobject'), true), "\n";
echo 'FICHA_UNIDAD=', (new sfIsadPlugin($u))->referenceCode, "\n";
echo 'FICHA_SUBSERIE=', (new sfIsadPlugin($byIdent['24 mn']))->referenceCode, "\n";
echo 'EAD_UNIDAD=', sfEadPlugin::getUnitidValue($u), "\n";
echo 'EAD_SUBSERIE=', sfEadPlugin::getUnitidValue($byIdent['24 mn']), "\n";
echo 'PADRE_UNIDAD=', $u->parent->identifier, ' | ', $u->parent->parent->identifier, ' | ', $u->parent->parent->parent->identifier, "\n";

QubitSearch::getInstance()->populate(['excludeTypes' => ['aip', 'term', 'actor', 'accession', 'repository', 'functionobject']]);
$index = (new \Elastica\Client(arElasticSearchPluginConfiguration::$config['server']))->getIndex(getenv('RC_INDEX').'_qubitinformationobject');
QubitSearch::getInstance()->flushBatch();
$index->refresh();
echo 'IDX_COUNT=', $index->count(), "\n";
$doc = $index->search(new \Elastica\Query\Term(['slug' => $u->slug]))->getResults()[0]->getData();
echo 'IDX_IDENTIFIER=', $doc['identifier'], "\n";
echo 'IDX_REFERENCECODE=', $doc['referenceCode'], "\n";
// Lo que muestran los resultados (search/_searchResult.php y QubitHelper): referenceCode solo con el ajuste en 1.
echo 'MOSTRADO=', ('1' == sfConfig::get('app_inherit_code_informationobject', 1) && !empty($doc['referenceCode'])) ? $doc['referenceCode'] : $doc['identifier'], "\n";
foreach (['SIGNATURA' => '"'.$sig.'"', 'NUMERO' => '5640', 'SUBSERIE' => '"24 mn"', 'SERIE' => '"C II"'] as $label => $q) {
    $bool = new \Elastica\Query\BoolQuery();
    $bool->addMust(arElasticSearchPluginUtil::generateQueryString($q, ['identifier' => 1, 'referenceCode' => 1, 'i18n.es.title' => 1]));
    $hits = array_map(static fn ($h) => $h->getData()['identifier'], $index->search(new \Elastica\Query($bool))->getResults());
    sort($hits);
    echo "BUSCA_$label=", implode('|', $hits), "\n";
}
PHP

echo "== Contrato estático =="
CFG="$("${COMPOSE[@]}" config --format json)"
expect "reconcile monta el script RO" "True" \
  "$(python3 -c "import json,sys; v=[x for x in json.load(sys.stdin)['services']['reconcile']['volumes'] if x['target']=='/project/scripts/reconcile-reference-codes.php']; print(len(v)==1 and v[0].get('read_only') is True)" <<<"$CFG")"
expect "reconcile.sh ejecuta el paso tras el de contraseñas" "1" \
  "$(grep -A1 'reconcile-strong-passwords.php"$' config/atom/reconcile.sh | grep -c 'tools:run "$HERE/reconcile-reference-codes.php"' || true)"
expect "el script no usa flush_all" "0" "$(grep -vE '^[[:space:]]*(/?\*|//)' config/atom/reconcile-reference-codes.php | grep -ci 'flush' || true)"

echo "== A. Default upstream (1) → 0 =="
setv 1 # la DEV puede estar ya en 0 por un reconcile anterior; el default upstream es 1
OTHERS0="$(others "$SCRATCH")"
expect "precondición: 1 (default upstream)" "es/es=1 " "$(rsp "$SCRATCH")"

echo "== B. Antes del ajuste (1): comportamiento a corregir, con descripciones de prueba =="
php_scratch seed.php
expect "alta de las descripciones de prueba" "0" "$RC"
php_scratch inspect.php
expect "inspección con ajuste 1: exit 0" "0" "$RC"
expect "efectivo = 1" "'1'" "$(val SETTING)"
expect "ficha: el código heredado duplica la Signatura" "AHU AHC-C II-24 mn-5640 (Ind. C II -24 mn)" "$(val FICHA_UNIDAD)"
expect "EAD <unitid>: lo mismo" "AHC-C II-24 mn-5640 (Ind. C II -24 mn)" "$(val EAD_UNIDAD)"
expect "índice: referenceCode heredado" "AHU AHC-C II-24 mn-5640 (Ind. C II -24 mn)" "$(val IDX_REFERENCECODE)"
FP_B="$(admin "$SCRATCH" -e "SELECT CONCAT_WS('~', id, parent_id, lft, rgt, identifier, level_of_description_id) FROM information_object ORDER BY id" | sha256sum | cut -c1-16)"

echo "== C. Reconcile: 1 → 0 =="
reconcile_rsp
expect "exit 0" "0" "$RC"
expect_out "escribió y verificó" "inherit_code_informationobject: escrito 0 en la BD"
expect_out "postcondición" "OK: BD = 0, valor efectivo = 0"
expect "valor 0 en la BD" "es/es=0 " "$(rsp "$SCRATCH")"
expect "el resto de settings intacto" "$OTHERS0" "$(others "$SCRATCH")"
expect "la jerarquía y los identificadores no cambiaron" "$FP_B" "$(admin "$SCRATCH" -e "SELECT CONCAT_WS('~', id, parent_id, lft, rgt, identifier, level_of_description_id) FROM information_object ORDER BY id" | sha256sum | cut -c1-16)"

echo "== D. Después del ajuste (0): la Signatura se conserva y se representa tal cual =="
SIG='5640 (Ind. C II -24 mn)'
php_scratch inspect.php
expect "inspección con ajuste 0: exit 0" "0" "$RC"
expect "efectivo = 0" "'0'" "$(val SETTING)"
expect "ficha: unidad = Signatura íntegra" "$SIG" "$(val FICHA_UNIDAD)"
expect "ficha: subserie = su código completo" "24 mn" "$(val FICHA_SUBSERIE)"
expect "EAD <unitid>: unidad = Signatura íntegra" "$SIG" "$(val EAD_UNIDAD)"
expect "EAD <unitid>: subserie" "24 mn" "$(val EAD_SUBSERIE)"
expect "jerarquía intacta (la unidad sigue bajo 24 mn > C II > AHC)" "24 mn | C II | AHC" "$(val PADRE_UNIDAD)"
expect "índice: identifier = Signatura" "$SIG" "$(val IDX_IDENTIFIER)"
expect "resultados de búsqueda muestran la Signatura" "$SIG" "$(val MOSTRADO)"
expect "búsqueda por la Signatura entre comillas encuentra la unidad" "$SIG" "$(val BUSCA_SIGNATURA)"
expect "búsqueda por el número 5640 encuentra la unidad" "$SIG" "$(val BUSCA_NUMERO)"
echo "INFO  índice: referenceCode con ajuste 0 -> $(val IDX_REFERENCECODE)"
echo "INFO  búsqueda \"24 mn\" -> $(val BUSCA_SUBSERIE) ; búsqueda \"C II\" -> $(val BUSCA_SERIE)"

echo "== E. Idempotencia =="
FP1="$(fp "$SCRATCH")"
reconcile_rsp
expect "segundo run: exit 0" "0" "$RC"
expect_out "sin escritura" "ya es 0 en la BD; sin escritura"
expect "tablas de settings idénticas" "$FP1" "$(fp "$SCRATCH")"

echo "== F. Override en otra cultura → STOP (88) antes de escribir =="
admin "$SCRATCH" -e "UPDATE setting_i18n i JOIN setting s ON s.id = i.id AND i.culture = s.source_culture SET i.value = '1' WHERE s.name = 'inherit_code_informationobject'"
admin "$SCRATCH" -e "INSERT INTO setting_i18n (id, culture, value) SELECT id, 'fr', '1' FROM setting WHERE name = 'inherit_code_informationobject'"
FP2="$(fp "$SCRATCH")"
reconcile_rsp
expect "exit 88" "88" "$RC"
expect_out "NO RECONCILIABLE" "NO RECONCILIABLE"
expect "ninguna escritura" "$FP2" "$(fp "$SCRATCH")"
admin "$SCRATCH" -e "DELETE i FROM setting_i18n i JOIN setting s ON s.id = i.id WHERE s.name = 'inherit_code_informationobject' AND i.culture = 'fr'"

echo "== G. Fila ausente → se crea con 0 =="
admin "$SCRATCH" -e "DELETE i FROM setting_i18n i JOIN setting s ON s.id = i.id WHERE s.name = 'inherit_code_informationobject'; DELETE FROM setting WHERE name = 'inherit_code_informationobject'"
reconcile_rsp
expect "exit 0" "0" "$RC"
expect_out "creada" "no existía; creada con valor 0"
expect "valor 0" "1" "$(admin "$SCRATCH" -e "SELECT COUNT(*) FROM setting s JOIN setting_i18n i ON i.id = s.id WHERE s.name = 'inherit_code_informationobject' AND i.value = '0'")"

expect "la BD real no cambió durante los casos scratch" "$DEV_FP_BEFORE" "$(fp atom)"
expect "el índice atom_qubitinformationobject de la DEV sigue sin documentos" "0" \
  "$("${COMPOSE[@]}" exec -T elasticsearch curl -s 'localhost:9200/atom_qubitinformationobject/_count' | python3 -c 'import json,sys; print(json.load(sys.stdin)["count"])')"

echo "== H. Reconcile real sobre la DEV (invalida la caché compartida) =="
"${COMPOSE[@]}" run --rm --no-deps -T reconcile >"$TMP/rec.log" 2>&1 || true
expect "reconcile completo: paso aplicado y verificado" "1" "$(grep -c 'inherit_code_informationobject: OK: BD = 0' "$TMP/rec.log" || true)"
expect "DEV: valor 0" "es/es=0 " "$(rsp atom)"
FP_DEV="$(fp atom)"
"${COMPOSE[@]}" run --rm --no-deps -T reconcile >"$TMP/rec2.log" 2>&1 || true
expect "DEV: repetido sin escritura" "1;$FP_DEV" "$(grep -c 'inherit_code_informationobject: ya es 0 en la BD; sin escritura' "$TMP/rec2.log" || true);$(fp atom)"

echo
if ((fails)); then echo "test-reconcile-reference-codes: $fails fallo(s)"; exit 1; fi
echo "test-reconcile-reference-codes: OK"
