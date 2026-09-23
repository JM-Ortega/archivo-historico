#!/usr/bin/env bash
# Prueba de integración de la configuración crítica de AtoM:
#   default_culture=es · timezone único (PHP + Symfony) · csrf_secret real/estable/externo · check_for_updates=0
#
#   config/atom/tests/test-critical-config.sh          # tarda varios minutos (dos instalaciones desde cero)
#
# Aislamiento total: NO toca la instancia DEV. Levanta su propio proyecto Compose (`archivo-historico-cc-<id>`, puerto
# loopback libre) con las imágenes ya construidas (no las reasigna: no hace build), lo modifica a voluntad y lo elimina al
# terminar (`down -v` SOLO de su proyecto). Requiere las imágenes `archivo-historico/atom:2.10.2` y `.../nginx:2.10.2`.
#
# El secreto CSRF real NUNCA se imprime: solo se comprueba por igualdad interna (dentro del contenedor) y por ausencia
# (`grep -Ff` con el secreto leído de un fichero temporal 0600 del host, sin pasarlo por argv).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
PROJECT="archivo-historico-cc-$RUN"
PORT="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])')"
export ATOM_WEB_PORT="$PORT"
URL="http://127.0.0.1:$PORT"
DC=(docker compose -p "$PROJECT")
IMG=archivo-historico/atom:2.10.2
SINK="$PROJECT-sink"
ADMIN_EMAIL="${ATOM_ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ATOM_ADMIN_PASSWORD:-admin_dev_12345}"
SECRET_FILE_IN=/run/atom-secrets/csrf_secret
TMP="$(mktemp -d)"
fails=0
OUT=
RC=0

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:100}"; else echo "FAIL  $1 -> ${3:0:400} (esperado ${2:0:400})"; fails=$((fails + 1)); fi
}
expect_ne() { # <descripción> <no esperado> <resultado>
  if [[ "$3" != "$2" ]]; then echo "PASS  $1 -> ${3:0:100}"; else echo "FAIL  $1 -> ${3:0:400} (no debía ser ${2:0:400})"; fails=$((fails + 1)); fi
}

cleanup() {
  docker rm -f "$SINK" >/dev/null 2>&1 || true
  "${DC[@]}" down -v >/dev/null 2>&1 || true
  # Los ficheros que escriben los contenedores (root) no los puede borrar el usuario del host.
  docker run --rm -v "$TMP:/w" --entrypoint sh "$IMG" -c 'rm -rf /w/* /w/.[!.]*' >/dev/null 2>&1 || true
  rm -rf "$TMP"
}
trap cleanup EXIT

q() { "${DC[@]}" exec -T percona sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" atom --batch --skip-column-names -e "$0" 2>/dev/null' "$1"; }
in_atom() { "${DC[@]}" exec -T atom "$@"; }
svc_id() { "${DC[@]}" ps -aq "$1"; }
started_at() { docker inspect -f '{{.State.StartedAt}}' "$(svc_id "$1")"; }
last_log() { docker logs --since "$(started_at "$1")" "$(svc_id "$1")" 2>&1; }
# Huella (no el valor) del secreto del proyecto; permite comparar entre arranques sin revelarlo.
fingerprint() { in_atom sha256sum "$SECRET_FILE_IN" | cut -c1-16; }
# Símbolo interno: ¿el secreto de settings.yml coincide con el fichero? (igualdad dentro del contenedor)
PROBE='<?php
$file = trim(file_get_contents("/run/atom-secrets/csrf_secret"));
$sec = (string) sfConfig::get("sf_csrf_secret");
echo "P:", implode("|", [
  sfConfig::get("sf_default_culture"), sfConfig::get("sf_default_timezone"), date_default_timezone_get(), ini_get("date.timezone"),
  hash_equals($file, $sec) ? "secret=file" : "secret!=file", "change_me" === $sec ? "CHANGE_ME" : "not-change_me",
]), "\n";'
probe() { # [servicio] → "cultura|tz symfony|tz php efectivo|date.timezone|secret=file|not-change_me"
  local svc=${1:-atom}
  "${DC[@]}" exec -T "$svc" sh -c 'cat >/tmp/probe.php; cd /atom/src && php symfony tools:run /tmp/probe.php' <<<"$PROBE" | sed -n 's/^P://p'
}

# --- HTTP ---
csrf() { grep -o 'name="_csrf_token" value="[0-9a-f]*"' "$1" | head -n1 | sed 's/.*value="//;s/"$//'; }
login() { # <jar> <email> <password> <csrf> → HTTP code
  curl -sS -o /dev/null -w '%{http_code}' -c "$1" -b "$1" --data-urlencode "_csrf_token=$4" \
    --data-urlencode "email=$2" --data-urlencode "password=$3" --data-urlencode next= "$URL/index.php/user/login"
}
logged_in() { # <jar> → yes|no (con `pipefail`, `curl | grep -q` daría falsos negativos)
  local body
  body="$(curl -sS -c "$1" -b "$1" "$URL/")"
  if grep -q 'user/logout' <<<"$body"; then echo yes; else echo no; fi
}
admin_visit() { # sesión NUEVA de administrador que pide la portada (dispara el componente update-check si está habilitado) → jar
  local jar="$TMP/j$RANDOM"
  curl -sS -c "$jar" -b "$jar" -o "$TMP/l.html" "$URL/user/login"
  login "$jar" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "$(csrf "$TMP/l.html")" >/dev/null
  curl -sS -c "$jar" -b "$jar" -o /dev/null "$URL/"
  echo "$jar"
}
# Sumidero de red: alias DNS www.accesstomemory.org dentro del proyecto; cuenta conexiones TCP entrantes (nada sale a Internet).
start_sink() {
  docker run -d --rm --name "$SINK" --network "${PROJECT}_default" --network-alias www.accesstomemory.org --entrypoint php "$IMG" -r \
    '$s = stream_socket_server("tcp://0.0.0.0:443"); while (true) { if ($c = @stream_socket_accept($s, -1)) { file_put_contents("/tmp/conn", "x\n", FILE_APPEND); fclose($c); } }' >/dev/null
  sleep 1
}
conns() { docker exec "$SINK" sh -c 'cat /tmp/conn 2>/dev/null | wc -l' | tr -d ' \n'; }
mc() { # <comando memcached ASCII con \r\n> → respuesta (efímero: contenedor aparte, sin wrapper)
  "${DC[@]}" run --rm --no-deps -T -e MC="$1" --entrypoint bash reconcile -c 'exec 3<>/dev/tcp/memcached/11211; printf "$MC" >&3; sleep 0.3; timeout 1 cat <&3 | tr -d "\r"' 2>/dev/null
}
# Todas las filas de settings salvo check_for_updates (preservación de ajenos): una fila por línea, orden determinista y salida COMPLETA.
# Se hashea en el shell (setting_hash), NO con MD5(GROUP_CONCAT(...)): group_concat_max_len (1024 por defecto) truncaría el material en
# silencio (~46 KB reales) y la huella solo vería las primeras filas.
setting_rows() {
  q 'SELECT CONCAT_WS("~", s.id, s.name, IFNULL(s.scope,""), s.source_culture, IFNULL(i.culture,""), IFNULL(i.value,"")) FROM setting s LEFT JOIN setting_i18n i ON i.id = s.id WHERE s.name <> "check_for_updates" ORDER BY s.id, i.culture'
}
setting_hash() { setting_rows | sha256sum | cut -d' ' -f1; }
cfu_rows() { q 'SELECT CONCAT(i.culture, "=", i.value) FROM setting s JOIN setting_i18n i ON i.id = s.id WHERE s.name = "check_for_updates" ORDER BY i.culture' | tr '\n' ' '; }
data_hash() { q 'CHECKSUM TABLE object, actor, actor_i18n, information_object, information_object_i18n, term, term_i18n, taxonomy, user, slug' | tr '\t\n' ' ~'; }
cfu() { q 'SELECT CONCAT_WS("/", s.source_culture, i.culture, i.value) FROM setting s JOIN setting_i18n i ON i.id = s.id WHERE s.name = "check_for_updates"'; }

# ------------------------------------------------------------------------------------------------------------------
echo "== 0. Contrato estático (compose) =="
CFG="$("${DC[@]}" config --format json)"
py() { python3 -c "import json,sys; d=json.load(sys.stdin)['services']; $1" <<<"$CFG"; }
RT="atom atom_worker bootstrap reconcile"
expect "los cuatro contextos AtoM usan el entrypoint del proyecto" "4" \
  "$(py "print(sum(d[s].get('entrypoint') == ['bash','/project/scripts/runtime-config.sh'] for s in '$RT'.split()))")"
expect "ningún otro servicio lo usa" "0" "$(py "print(sum('runtime-config' in json.dumps(d[s].get('entrypoint')) for s in d if s not in '$RT'.split()))")"
expect "UNA intención de timezone: los cuatro reciben el mismo ATOM_PHP_DATE_TIMEZONE (DEV: America/Bogota)" "America/Bogota" \
  "$(py "print(' '.join(sorted({d[s]['environment']['ATOM_PHP_DATE_TIMEZONE'] for s in '$RT'.split()})))")"
expect "el secreto llega como fichero: ATOM_CSRF_SECRET_FILE, y ninguna variable lo lleva por valor" "$SECRET_FILE_IN;0" \
  "$(py "e=[d[s]['environment'] for s in '$RT'.split()]; print(e[0]['ATOM_CSRF_SECRET_FILE']+';'+str(sum(1 for x in e for k in x if 'CSRF' in k and k!='ATOM_CSRF_SECRET_FILE')))")"
expect "el volumen del secreto es RW solo en dev_secrets; RO en los cuatro contextos AtoM" "rw;ro ro ro ro" \
  "$(py "f=lambda s:[('ro' if v.get('read_only') else 'rw') for v in d[s]['volumes'] if v['target']=='/run/atom-secrets']; print(f('dev_secrets')[0]+';'+' '.join(f(s)[0] for s in '$RT'.split()))")"
expect "bootstrap depende de dev_secrets (completed_successfully)" "service_completed_successfully" "$(py "print(d['bootstrap']['depends_on']['dev_secrets']['condition'])")"
expect "ATOM_TIMEZONE alimenta la MISMA variable en los cuatro contextos" "America/Lima" \
  "$(ATOM_TIMEZONE=America/Lima "${DC[@]}" config --format json | python3 -c "import json,sys; d=json.load(sys.stdin)['services']; print(' '.join(sorted({d[s]['environment']['ATOM_PHP_DATE_TIMEZONE'] for s in '$RT'.split()})))")"
expect "el repo no versiona ningún secreto (.env ignorado, sin change_me real ni ficheros de secreto)" "0" \
  "$(git ls-files | grep -Ec '(^|/)\.env($|\.)|\.key$|csrf_secret$' || true)"

# ------------------------------------------------------------------------------------------------------------------
echo "== 1. Unidad: runtime-config.sh y dev-secrets.sh (contenedores desechables, sin instalar nada) =="
W="$TMP/unit"
new_case() { # deja $W listo: template upstream real, stub del entrypoint upstream y un secreto de prueba válido
  docker run --rm -v "$TMP:/w" --entrypoint sh "$IMG" -c 'rm -rf /w/unit' >/dev/null 2>&1 || true
  mkdir -p "$W/apps/qubit/config" "$W/docker"
  cp upstream/atom/apps/qubit/config/settings.yml.tmpl "$W/apps/qubit/config/"
  printf '#!/usr/bin/env bash\necho "STUB $*"\nchmod -R a+rwX "$ATOM_SRC"\n' >"$W/docker/entrypoint.sh"; chmod +x "$W/docker/entrypoint.sh"
  printf 'unit%s' "$(head -c24 /dev/urandom | od -An -tx1 | tr -d ' \n')" >"$W/secret"; chmod 644 "$W/secret"
}
rc_run() { # [-e VAR=val ...] → $OUT $RC (el wrapper real, contra $W; el stub hace de entrypoint upstream)
  RC=0
  OUT=$("${DC[@]}" run --rm --no-deps -T -v "$W:/fake" -e ATOM_SRC=/fake -e ATOM_CSRF_SECRET_FILE=/fake/secret "$@" bootstrap arg1 arg2 2>&1) || RC=$?
}
S="$W/apps/qubit/config/settings.yml"
new_case
rc_run
expect "runtime-config: éxito y delega en el upstream con los argumentos" "0;STUB arg1 arg2" "$RC;$(tail -n1 <<<"$OUT")"
expect "settings.yml: default_culture es" "es" "$(sed -n 's/^ *default_culture: *//p' "$S")"
expect "settings.yml: default_timezone = ATOM_PHP_DATE_TIMEZONE" "America/Bogota" "$(sed -n 's/^ *default_timezone: *//p' "$S")"
expect "settings.yml: csrf_secret = el del fichero (comparado sin imprimirlo)" "1" "$(grep -E '^ +csrf_secret:' "$S" | grep -cFf "$W/secret" || true)"
expect "settings.yml: ya no queda change_me" "0" "$(grep -c change_me "$S" || true)"
expect "el .tmpl derivado coincide (tools:install lo regenera desde él)" "0" "$(cmp -s "$S" "$W/apps/qubit/config/settings.yml.tmpl"; echo $?)"
expect "el secreto no se imprime en la salida" "0" "$(grep -cFf "$W/secret" <<<"$OUT" || true)"
expect "el resto del template upstream queda intacto (solo cambian 3 líneas)" "3" "$(diff <(cat upstream/atom/apps/qubit/config/settings.yml.tmpl) "$S" | grep -c '^>' || true)"
H1="$(sha256sum "$S" | cut -d' ' -f1)"
rc_run
expect "idempotente: segunda ejecución (sobre el .tmpl ya derivado) deja el mismo resultado" "0;$H1" "$RC;$(sha256sum "$S" | cut -d' ' -f1)"
rc_run -e ATOM_PHP_DATE_TIMEZONE=America/Lima
expect "la ÚNICA variable de timezone cambia el timezone de Symfony" "America/Lima" "$(sed -n 's/^ *default_timezone: *//p' "$S")"

new_case; rm "$W/secret"; rc_run
expect "sin fichero de secreto → 64" "64" "$RC"
expect_ne "y no delega en el upstream" "1" "$(grep -c '^STUB' <<<"$OUT" || true)"
expect "y no escribió settings.yml" "0" "$([[ -e "$S" ]] && echo 1 || echo 0)"
for bad in 'change_me' 'corto' 'con espacios en el secreto que es largo de sobra' 'sec:ret=con*chars/raros-que-son-largos-de-sobra'; do
  new_case; printf '%s' "$bad" >"$W/secret"; rc_run
  expect "secreto inválido ('${bad:0:12}…') → 64 sin escribir" "64;0" "$RC;$([[ -e "$S" ]] && echo 1 || echo 0)"
done
new_case; : >"$W/secret"; rc_run; expect "secreto vacío → 64" "64" "$RC"
new_case; rc_run -e ATOM_PHP_DATE_TIMEZONE=Mars/Olympus; expect "timezone inválido → 64" "64" "$RC"
new_case; rc_run -e ATOM_PHP_DATE_TIMEZONE=; expect "sin timezone (no se cae en el America/Vancouver de upstream) → 64" "64" "$RC"
new_case; sed -i '/default_culture/d' "$W/apps/qubit/config/settings.yml.tmpl"; rc_run
expect "template upstream sin forma esperada → 65 y no se aplica nada" "65;0" "$RC;$([[ -e "$S" ]] && echo 1 || echo 0)"

DS() { RC=0; OUT=$("${DC[@]}" run --rm --no-deps -T -v "$TMP/sec:/sec" -e ATOM_SECRETS_DIR=/sec --entrypoint bash dev_secrets /project/scripts/dev-secrets.sh 2>&1) || RC=$?; }
docker run --rm -v "$TMP:/w" --entrypoint sh "$IMG" -c 'rm -rf /w/sec; mkdir -p /w/sec; chmod 777 /w/sec'
DS
expect "dev-secrets: genera el secreto" "0" "$RC"
docker run --rm -v "$TMP/sec:/sec" --entrypoint sh "$IMG" -c 'cat /sec/csrf_secret > /sec/copy; chmod 644 /sec/copy; stat -c %a /sec/csrf_secret'  >"$TMP/mode"
expect "dev-secrets: 0600 (solo root)" "600" "$(cat "$TMP/mode")"
expect "dev-secrets: 256 bits en hex (64 caracteres)" "64" "$(wc -c <"$TMP/sec/copy" | tr -d ' ')"
expect "dev-secrets: no imprime el valor" "0" "$(grep -cFf "$TMP/sec/copy" <<<"$OUT" || true)"
expect "dev-secrets: el valor no es constante ni change_me" "0" "$(grep -c change_me "$TMP/sec/copy" || true)"
H1="$(sha256sum "$TMP/sec/copy" | cut -d' ' -f1)"
DS
expect "dev-secrets: segunda ejecución sin cambios (estable)" "0;$H1" "$RC;$(docker run --rm -v "$TMP/sec:/sec" --entrypoint sh "$IMG" -c 'cat /sec/csrf_secret' | sha256sum | cut -d' ' -f1)"
docker run --rm -v "$TMP/sec:/sec" --entrypoint sh "$IMG" -c 'echo corto > /sec/csrf_secret'
DS
expect "dev-secrets: un fichero existente inválido es STOP (no se sustituye en silencio)" "1;corto" \
  "$RC;$(docker run --rm -v "$TMP/sec:/sec" --entrypoint sh "$IMG" -c 'cat /sec/csrf_secret')"

# ------------------------------------------------------------------------------------------------------------------
echo "== 2. Fresh install aislado: docker compose up -d --wait (A, B, C, D) =="
UP_RC=0
"${DC[@]}" up -d --wait >"$TMP/up1.log" 2>&1 || UP_RC=$?
expect "A. up -d --wait termina con éxito" "0" "$UP_RC"
for s in bootstrap reconcile theme_build dev_secrets; do expect "A. $s terminó con 0" "0" "$(docker inspect -f '{{.State.ExitCode}}' "$(svc_id "$s")")"; done
for s in atom atom_worker nginx; do expect "A. $s healthy" "healthy" "$(docker inspect -f '{{.State.Health.Status}}' "$(svc_id "$s")")"; done
expect "A. web READY" "READY" "$(ATOM_WEB_PORT=$PORT scripts/web-ready.sh --wait 2>/dev/null | sed -E 's/^web-ready: (READY).*/\1/')"
expect "A. bootstrap instaló una vez (FRESH)" "1" "$(last_log bootstrap | grep -c 'BD FRESH: ejecutando tools:install' || true)"
expect "A. reconcile: plugin habilitado y check_for_updates convergido" "2" \
  "$(last_log reconcile | grep -cE 'arUnicaucaB5Plugin: habilitado y verificado|check_for_updates: OK: BD = 0' || true)"

# Secreto real del proyecto → fichero temporal 0600 del host (solo para `grep -Ff`; nunca se imprime).
( umask 077; in_atom cat "$SECRET_FILE_IN" >"$TMP/real.secret" )
expect "D. el secreto real es válido (>= 32 caracteres)" "yes" "$([[ "$(wc -c <"$TMP/real.secret")" -ge 32 ]] && echo yes || echo no)"
FP1="$(fingerprint)"

echo "-- B/C. cultura y timezone (runtime: atom y atom_worker) --"
for s in atom atom_worker; do
  expect "B/C/D. $s: culture=es | Symfony=Bogota | PHP efectivo=Bogota | php.ini=Bogota | secreto=fichero | no change_me" \
    "es|America/Bogota|America/Bogota|America/Bogota|secret=file|not-change_me" "$(probe "$s")"
done
expect "C. PHP ANTES de Symfony (CLI puro, solo php.ini) ve America/Bogota" "America/Bogota" "$(in_atom php -r 'echo date_default_timezone_get();')"
expect "C. sin divergencia: php.ini y default_timezone salen de la misma variable" "America/Bogota;America/Bogota" \
  "$(in_atom sh -c 'echo "$ATOM_PHP_DATE_TIMEZONE;$(sed -n "s/^ *default_timezone: *//p" /atom/src/apps/qubit/config/settings.yml)"')"
echo "-- B. el INSTALL corrió con culture es (artefactos creados sin culture explícita) --"
expect "B. site settings creados por tools:install (siteTitle, siteDescription, siteBaseUrl) → source_culture es" "es es es" \
  "$(q 'SELECT GROUP_CONCAT(source_culture ORDER BY name SEPARATOR " ") FROM setting WHERE name IN ("siteTitle","siteDescription","siteBaseUrl")')"
expect "B. el actor del administrador (creado por tools:install) → source_culture es" "es" \
  "$(q 'SELECT a.source_culture FROM actor a JOIN user u ON u.id = a.id WHERE u.username = "admin"')"
expect "B. las fixtures con culture explícita 'en' conservan 'en' (no se reescribe)" "yes" \
  "$([[ "$(q 'SELECT COUNT(*) FROM setting WHERE source_culture = "en"')" -gt 0 ]] && echo yes || echo no)"
echo "-- C. los timestamps que escribe la aplicación usan America/Bogota (DATETIME sin zona; el servidor MySQL está en UTC) --"
expect "C. install: created_at más reciente = UTC-5 (UTC-7 sería el default upstream Vancouver)" "5" \
  "$(q 'SELECT TIMESTAMPDIFF(HOUR, MAX(created_at), UTC_TIMESTAMP()) FROM object')"
echo "-- C. una sola variable gobierna ambos (contenedor desechable con otra zona) --"
cat >"$TMP/tz.php" <<'PHP'
<?php echo "T:", sfConfig::get('sf_default_timezone'), '|', date_default_timezone_get(), '|', ini_get('date.timezone'), "\n";
PHP
expect "C. ATOM_PHP_DATE_TIMEZONE=America/Lima → php.ini y Symfony a la vez" "America/Lima|America/Lima|America/Lima" \
  "$("${DC[@]}" run --rm --no-deps -T -e ATOM_PHP_DATE_TIMEZONE=America/Lima -v "$TMP/tz.php:/tmp/tz.php:ro" reconcile bash -c 'cd /atom/src && php symfony tools:run /tmp/tz.php' 2>&1 | sed -n 's/^T://p')"

echo "-- D. CSRF --"
# El bootstrap (que instaló) recibió el mismo secreto: su settings.yml (contenedor detenido) lo contiene.
docker cp "$(svc_id bootstrap):/atom/src/apps/qubit/config/settings.yml" "$TMP/bootstrap-settings.yml" >/dev/null
expect "D. el settings.yml del contenedor bootstrap (que corrió tools:install) lleva el secreto real y cultura es" "1;es" \
  "$(grep -cFf "$TMP/real.secret" "$TMP/bootstrap-settings.yml" || true);$(sed -n 's/^ *default_culture: *//p' "$TMP/bootstrap-settings.yml")"
expect "D. atom y atom_worker comparten el secreto (misma huella)" "$FP1" "$("${DC[@]}" exec -T atom_worker sha256sum "$SECRET_FILE_IN" | cut -c1-16)"
expect "D. el volumen del secreto es de solo lectura en atom" "readonly" "$(in_atom sh -c 'touch /run/atom-secrets/x 2>/dev/null && echo writable || echo readonly')"
# Formularios CSRF: token válido autentica; inválido no; y el token real NO se deriva de change_me (md5(secreto . sid . clase)).
JAR="$TMP/jar"
curl -sS -c "$JAR" -b "$JAR" -o "$TMP/login.html" "$URL/user/login"
TOKEN="$(csrf "$TMP/login.html")"
SID="$(awk '$6=="symfony"{print $7}' "$JAR" | cut -d: -f1)"
expect "D. el token del formulario coincide con md5(secreto_real . sid . 'sfForm') (comprobado dentro del contenedor)" "match" \
  "$(in_atom php -r '$s = trim(file_get_contents("/run/atom-secrets/csrf_secret")); echo md5($s.$argv[1]."sfForm") === $argv[2] ? "match" : "nomatch";' -- "$SID" "$TOKEN")"
expect_ne "D. el token real no es el derivado de change_me" "$(printf 'change_me%ssfForm' "$SID" | md5sum | cut -d' ' -f1)" "$TOKEN"
login "$TMP/bad" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "csrf-invalido" >/dev/null
expect "D. CSRF inválido no autentica" "no" "$(logged_in "$TMP/bad")"
BADTOK="$(printf 'change_me%ssfForm' "$SID" | md5sum | cut -d' ' -f1)"
login "$JAR" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "$BADTOK" >/dev/null
expect "D. un token construido con change_me y el sid conocido no autentica" "no" "$(logged_in "$JAR")"
curl -sS -c "$JAR" -b "$JAR" -o "$TMP/login.html" "$URL/user/login"
expect "D. token válido: login correcto (302) y sesión autenticada" "302;yes" \
  "$(login "$JAR" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "$(csrf "$TMP/login.html")");$(logged_in "$JAR")"
KEEP="$JAR"
echo "-- D. el secreto no aparece en ningún sitio observable --"
{ "${DC[@]}" config; for id in $("${DC[@]}" ps -aq); do docker inspect "$id"; done; docker volume inspect "${PROJECT}_atom_secrets"; } >"$TMP/observable.txt" 2>&1
expect "D. ni en docker inspect / compose config" "0" "$(grep -cFf "$TMP/real.secret" "$TMP/observable.txt" || true)"
{ "${DC[@]}" logs 2>&1; cat "$TMP/up1.log"; } >"$TMP/logs.txt"
expect "D. ni en los logs de ningún servicio" "0" "$(grep -cFf "$TMP/real.secret" "$TMP/logs.txt" || true)"
expect "D. ni en el árbol versionado (git grep)" "0" "$(git grep -lF -f "$TMP/real.secret" -- . ':!upstream' 2>/dev/null | wc -l | tr -d ' ')"
expect "D. ni en la imagen (docker history)" "0" "$(docker history --no-trunc "$IMG" | grep -cFf "$TMP/real.secret" || true)"

# ------------------------------------------------------------------------------------------------------------------
echo "== 3. check_for_updates = 0 (E) =="
start_sink
expect "E. DB desired state: fila única en 0 (cultura fuente es)" "es/es/0" "$(cfu)"
C0="$(conns)"; admin_visit >/dev/null
expect "E. primera request admin (sesión nueva) NO produce update-check externo" "$C0" "$(conns)"

echo "-- E. control positivo del sumidero + caché obsoleta --"
q 'UPDATE setting_i18n i JOIN setting s ON s.id = i.id AND i.culture = s.source_culture SET i.value = "1" WHERE s.name = "check_for_updates"'
cat >"$TMP/inval.php" <<'PHP'
<?php QubitCache::getInstance()->removePattern('settings:i18n:*'); echo "I:done\n";
PHP
cat >"$TMP/cache.php" <<'PHP'
<?php $c = QubitCache::getInstance(); $k = 'settings:i18n:es';
echo 'K:', $c->has($k) ? (string) (unserialize($c->get($k))['app_check_for_updates'] ?? 'unset') : 'absent', "\n";
PHP
tools_run() { "${DC[@]}" run --rm --no-deps -T -v "$TMP/$1:/tmp/x.php:ro" reconcile bash -c 'cd /atom/src && php symfony tools:run /tmp/x.php' 2>/dev/null; }
tools_run inval.php >/dev/null
C1="$(conns)"; admin_visit >/dev/null
expect_ne "E. control positivo: con check_for_updates=1 la request admin SÍ intenta salir (el sumidero funciona)" "$C1" "$(conns)"
expect "E. la caché de settings quedó poblada con 1" "1" "$(tools_run cache.php | sed -n 's/^K://p')"
# Camino histórico: `tools:settings set` cambia la BD pero NO invalida la caché.
"${DC[@]}" run --rm --no-deps -T reconcile bash -c 'cd /atom/src && php symfony tools:settings set check_for_updates 0 --culture=es >/dev/null' >/dev/null 2>&1
expect "E. tools:settings set dejó la BD en 0…" "es/es/0" "$(cfu)"
expect "E. …pero la caché sigue con 1 (obsoleta)" "1" "$(tools_run cache.php | sed -n 's/^K://p')"
C2="$(conns)"; admin_visit >/dev/null
expect_ne "E. y por eso las requests seguirían intentando salir (por eso un exit 0 no basta)" "$C2" "$(conns)"

echo "-- E. reconcile: BD ya en 0 + caché obsoleta → invalida y satisface la postcondición --"
mc 'set wu13e-marker 0 0 2\r\nok\r\n' >/dev/null
SETH="$(setting_hash)"
echo "-- E. la huella de settings ajenos cubre TODO el material (sin truncado por group_concat_max_len) --"
expect "el material completo supera con creces group_concat_max_len (1024)" "yes" "$([[ "$(setting_rows | wc -c)" -gt 20000 ]] && echo yes || echo no)"
LATE_ID="$(q 'SELECT MAX(i.id) FROM setting_i18n i JOIN setting s ON s.id = i.id WHERE s.name <> "check_for_updates" AND i.value IS NOT NULL')"
q "UPDATE setting_i18n SET value = CONCAT(value, 'x') WHERE id = $LATE_ID AND value IS NOT NULL"
expect_ne "un cambio en la ÚLTIMA fila de settings (muy por encima de los primeros 1024 caracteres) cambia la huella" "$SETH" "$(setting_hash)"
q "UPDATE setting_i18n SET value = LEFT(value, CHAR_LENGTH(value) - 1) WHERE id = $LATE_ID AND value IS NOT NULL"
expect "restaurado el valor, la huella vuelve a ser la original" "$SETH" "$(setting_hash)"
REC_RC=0; REC_OUT="$("${DC[@]}" run --rm --no-deps -T reconcile 2>&1)" || REC_RC=$?
expect "E. reconcile exit 0" "0" "$REC_RC"
expect "E. la BD ya estaba en 0: sin escritura" "1" "$(grep -c 'check_for_updates: ya es 0 en la BD; sin escritura' <<<"$REC_OUT" || true)"
expect "E. la caché obsoleta ya no conserva el 1 (ausente o 0)" "1" "$([[ "$(tools_run cache.php | sed -n 's/^K://p')" =~ ^(absent|0)$ ]] && echo 1 || echo 0)"
C3="$(conns)"; admin_visit >/dev/null
expect "E. tras el reconcile, la request admin NO sale" "$C3" "$(conns)"
expect "E. sin flush_all: la clave ajena de Memcached sobrevive" "1" "$(mc 'get wu13e-marker\r\n' | grep -c '^ok$' || true)"
expect "E. sin flush_all: la sesión de administrador abierta antes sigue válida" "yes" "$(logged_in "$KEEP")"
expect "E. settings ajenos preservados (huella de todas las demás filas)" "$SETH" "$(setting_hash)"
expect "E. el código no usa flush_all" "0" "$(grep -vE '^[[:space:]]*(/?\*|//)' config/atom/reconcile-check-for-updates.php | grep -ci 'flush' || true)"

echo "-- E. override en otra cultura → NO RECONCILIABLE: exit 76 ANTES de escribir (sin cambios y sin invalidar la caché) --"
# Estado sintético: valor fuente != 0 + otra cultura con valor propio. El proyecto no define política para eliminar/sobrescribir el override.
q 'UPDATE setting_i18n i JOIN setting s ON s.id = i.id AND i.culture = s.source_culture SET i.value = "1" WHERE s.name = "check_for_updates"'
q 'INSERT INTO setting_i18n (id, culture, value) SELECT id, "fr", "fr-propio" FROM setting WHERE name = "check_for_updates"'
tools_run inval.php >/dev/null
admin_visit >/dev/null # puebla la caché de settings (settings:i18n:es) con el 1
expect "control: la caché de settings está poblada con 1 antes del reconcile" "1" "$(tools_run cache.php | sed -n 's/^K://p')"
ROWS1="$(cfu_rows)"; SETH2="$(setting_hash)"
REC_RC=0; REC_OUT="$("${DC[@]}" run --rm --no-deps -T reconcile 2>&1)" || REC_RC=$?
expect "estado sintético previo: fuente=1 y override fr=fr-propio" "es=1 fr=fr-propio " "$ROWS1"
expect "override conflictivo → exit 76" "76" "$REC_RC"
expect "el motivo es NO RECONCILIABLE (no una postcondición posterior a escribir)" "1;0" \
  "$(grep -c 'check_for_updates: NO RECONCILIABLE' <<<"$REC_OUT" || true);$(grep -c 'POSTCONDICIÓN NO SATISFECHA' <<<"$REC_OUT" || true)"
expect "ninguna escritura DB: las filas de check_for_updates quedan exactamente como estaban" "$ROWS1" "$(cfu_rows)"
expect "la fuente conserva su valor previo (1) y el override el suyo (fr-propio)" "es=1 fr=fr-propio " "$(cfu_rows)"
expect "settings ajenos intactos" "$SETH2" "$(setting_hash)"
expect "no se escribió ni se invalidó la caché (el log no lo dice)" "0" "$(grep -cE 'escrito 0 en la BD|caché de settings invalidada' <<<"$REC_OUT" || true)"
expect "la entrada de caché primada sigue ahí (sin invalidación previa al APPLY)" "1" "$(tools_run cache.php | sed -n 's/^K://p')"
# Limpieza del estado sintético (solo lo insertado aquí); la fuente queda en 1, como el siguiente bloque espera.
q 'DELETE i FROM setting_i18n i JOIN setting s ON s.id = i.id WHERE s.name = "check_for_updates" AND i.culture = "fr"'
expect "limpieza: vuelve a existir solo la fila de la cultura fuente" "es=1 " "$(cfu_rows)"

echo "-- E. reconcile: BD en 1 → converge a 0; repetición = no-op --"
q 'UPDATE setting_i18n i JOIN setting s ON s.id = i.id AND i.culture = s.source_culture SET i.value = "1" WHERE s.name = "check_for_updates"'
tools_run inval.php >/dev/null
tools_run cache.php >/dev/null
"${DC[@]}" run --rm --no-deps -T reconcile >"$TMP/rec2.log" 2>&1
expect "E. converge: BD = 0" "es/es/0" "$(cfu)"
expect "E. escribió (estaba en 1)" "1" "$(grep -c 'check_for_updates: escrito 0 en la BD' "$TMP/rec2.log" || true)"
expect "E. settings ajenos preservados" "$SETH" "$(setting_hash)"
CK1="$(q 'CHECKSUM TABLE setting, setting_i18n' | tr '\t\n' ' ~')"
"${DC[@]}" run --rm --no-deps -T reconcile >"$TMP/rec3.log" 2>&1
expect "E. repetición: sin escritura" "1" "$(grep -c 'ya es 0 en la BD; sin escritura' "$TMP/rec3.log" || true)"
expect "E. repetición: tablas de settings idénticas (no-op)" "$CK1" "$(q 'CHECKSUM TABLE setting, setting_i18n' | tr '\t\n' ' ~')"

# ------------------------------------------------------------------------------------------------------------------
echo "== 4. Estado compatible existente y repetición de up (F, H) =="
# Emula una BD compatible anterior a esta configuración: check_for_updates=1 y datos existentes (uno con source_culture en) que NO deben tocarse.
q 'UPDATE actor SET source_culture = "en" ORDER BY id LIMIT 1'
q 'UPDATE setting_i18n i JOIN setting s ON s.id = i.id AND i.culture = s.source_culture SET i.value = "1" WHERE s.name = "check_for_updates"'
tools_run inval.php >/dev/null
DATA1="$(data_hash)"; CULT1="$(q 'SELECT CONCAT(source_culture, ":", COUNT(*)) FROM actor GROUP BY source_culture ORDER BY 1' | tr '\n' ' ')"; SETH="$(setting_hash)"
IDS1="$("${DC[@]}" ps -q atom atom_worker nginx | sort | tr '\n' ' ')"
RECON1="$(started_at reconcile)"
UP_RC=0; "${DC[@]}" up -d --wait >"$TMP/up2.log" 2>&1 || UP_RC=$?
expect "H. segundo up -d --wait: exit 0" "0" "$UP_RC"
expect "F. bootstrap: BD compatible, no reinstala" "1;0" "$(last_log bootstrap | grep -c 'BD compatible; no se instala' || true);$(last_log bootstrap | grep -c 'tools:install' || true)"
expect_ne "F. reconcile se ejecutó de nuevo" "$RECON1" "$(started_at reconcile)"
expect "F. check_for_updates convergió a 0 selectivamente" "es/es/0" "$(cfu)"
expect "F. settings ajenos preservados" "$SETH" "$(setting_hash)"
expect "F. datos existentes intactos (object, actor, information_object, term, taxonomy, user, slug…: timestamps y cultures)" "$DATA1" "$(data_hash)"
expect "F. source_culture existentes sin cambios (el actor 'en' heredado sigue en 'en')" "$CULT1" "$(q 'SELECT CONCAT(source_culture, ":", COUNT(*)) FROM actor GROUP BY source_culture ORDER BY 1' | tr '\n' ' ')"
expect "H. dev_secrets no regenera el secreto" "1" "$(last_log dev_secrets | grep -c 'presente y válido; sin cambios' || true)"
expect "H. secreto estable tras el segundo up" "$FP1" "$(fingerprint)"
expect "H. atom/atom_worker/nginx sin churn" "$IDS1" "$("${DC[@]}" ps -q atom atom_worker nginx | sort | tr '\n' ' ')"
expect "H. culture/timezone/secreto siguen aplicados" "es|America/Bogota|America/Bogota|America/Bogota|secret=file|not-change_me" "$(probe atom)"
expect "H. web READY" "READY" "$(ATOM_WEB_PORT=$PORT scripts/web-ready.sh --wait 2>/dev/null | sed -E 's/^web-ready: (READY).*/\1/')"

echo "-- recreate ordinario de atom/atom_worker: mismo secreto y misma configuración --"
"${DC[@]}" rm -sf atom atom_worker >/dev/null 2>&1
"${DC[@]}" up -d --wait >"$TMP/up3.log" 2>&1
expect "recreate: atom y atom_worker son contenedores nuevos" "0" "$([[ "$IDS1" == *"$("${DC[@]}" ps -q atom)"* ]] && echo 1 || echo 0)"
expect "recreate: mismo secreto (huella)" "$FP1" "$(fingerprint)"
for s in atom atom_worker; do
  expect "recreate: $s con culture/timezone/secreto" "es|America/Bogota|America/Bogota|America/Bogota|secret=file|not-change_me" "$(probe "$s")"
done
expect "recreate: los formularios CSRF siguen funcionando" "302;yes" \
  "$(J="$TMP/jr"; curl -sS -c "$J" -b "$J" -o "$TMP/lr.html" "$URL/user/login"; login "$J" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "$(csrf "$TMP/lr.html")");$(logged_in "$TMP/jr")"

# ------------------------------------------------------------------------------------------------------------------
echo "== 5. RESET DEV: down -v → secreto nuevo en el siguiente fresh install =="
docker rm -f "$SINK" >/dev/null 2>&1 || true
"${DC[@]}" down -v >/dev/null 2>&1
expect "RESET: el volumen del secreto se elimina" "absent" "$(docker volume inspect "${PROJECT}_atom_secrets" >/dev/null 2>&1 && echo present || echo absent)"
UP_RC=0; "${DC[@]}" up -d --wait >"$TMP/up4.log" 2>&1 || UP_RC=$?
expect "RESET: el siguiente up -d --wait instala de nuevo" "0" "$UP_RC"
expect "RESET: fresh install otra vez (tools:install una vez)" "1" "$(last_log bootstrap | grep -c 'BD FRESH: ejecutando tools:install' || true)"
expect_ne "RESET: el secreto es NUEVO (huella distinta)" "$FP1" "$(fingerprint)"
expect "RESET: culture/timezone/secreto aplicados desde el principio" "es|America/Bogota|America/Bogota|America/Bogota|secret=file|not-change_me" "$(probe atom)"
expect "RESET: el nuevo install también nace en es" "es es es" \
  "$(q 'SELECT GROUP_CONCAT(source_culture ORDER BY name SEPARATOR " ") FROM setting WHERE name IN ("siteTitle","siteDescription","siteBaseUrl")')"

echo
if ((fails)); then echo "test-critical-config: $fails fallo(s)"; exit 1; fi
echo "test-critical-config: OK"
