#!/usr/bin/env bash
# Prueba de integración de la web DEV (Nginx → PHP-FPM → AtoM) sobre el proyecto DEV real:
# acceso desde el host, readiness de aplicación, FastCGI, assets, sesión/login, mounts RO y recreación.
#
#   scripts/test-web.sh
#
# No destruye estado: "recrear" = `rm -sf` + `up` de nginx/atom (nunca `down -v`); los artefactos de prueba
# (`web-test-<id>`) se escriben en uploads/downloads y se borran al terminar. Si el runtime no está arriba,
# lo levanta (el gate de bootstrap se aplica como siempre).
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose)
PORT="${ATOM_WEB_PORT:-8080}"
URL="${ATOM_WEB_URL:-http://localhost:$PORT}"
ADMIN_EMAIL="${ATOM_ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ATOM_ADMIN_PASSWORD:-admin_dev_12345}"
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
MARK="web-test-$RUN"
TMP="$(mktemp -d)"
fails=0

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:70}"; else echo "FAIL  $1 -> ${3:0:300} (esperado ${2:0:300})"; fails=$((fails + 1)); fi
}
expect_ne() { # <descripción> <no esperado> <resultado>
  if [[ "$3" != "$2" ]]; then echo "PASS  $1 -> ${3:0:70}"; else echo "FAIL  $1 -> ${3:0:300} (no debía ser ${2:0:300})"; fails=$((fails + 1)); fi
}

svc_id() { "${COMPOSE[@]}" ps -aq "$1"; }
in_svc() { local s="$1"; shift; "${COMPOSE[@]}" exec -T "$s" "$@"; }
code() { curl -sS --max-time 20 -o /dev/null -w '%{http_code}' "$@"; }
health() { docker inspect -f '{{.State.Health.Status}}' "$(svc_id "$1")"; }
ip_of() { docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$(svc_id "$1")"; }
csrf() { grep -o 'name="_csrf_token" value="[0-9a-f]*"' "$1" | head -n1 | sed 's/.*value="//;s/"$//'; }
login() { # <jar> <email> <password> <csrf> → HTTP code
  curl -sS -o /dev/null -w '%{http_code}' -c "$1" -b "$1" --data-urlencode "_csrf_token=$4" \
    --data-urlencode "email=$2" --data-urlencode "password=$3" --data-urlencode next= "$URL/index.php/user/login"
}
logged_in() { # <jar> → yes|no (enlace de logout presente en la portada)
  # Se captura el cuerpo antes de buscar: con `pipefail`, `curl | grep -q` falla (SIGPIPE de curl) en cuanto grep cierra la tubería.
  local body
  body="$(curl -sS -c "$1" -b "$1" "$URL/")"
  if grep -q 'user/logout' <<<"$body"; then echo yes; else echo no; fi
}

cleanup() {
  docker rm -f "web-test-ipsquat-$RUN" >/dev/null 2>&1 || true
  if [[ -n "$(svc_id atom)" ]] && [[ "$(docker inspect -f '{{.State.Status}}' "$(svc_id atom)")" == running ]]; then
    in_svc atom rm -f "/atom/src/uploads/$MARK" "/atom/src/downloads/$MARK" >/dev/null 2>&1 || true
  fi
  rm -rf "$TMP"
}
trap cleanup EXIT

echo "== I. La web forma parte del arranque por defecto =="
expect "sin profiles: nginx y su cadena (bootstrap, atom) están en el runtime por defecto" "atom bootstrap nginx" \
  "$(docker compose config --services | grep -xE 'atom|bootstrap|nginx' | sort | tr '\n' ' ' | sed 's/ $//')"

echo "== A. Runtime web =="
"${COMPOSE[@]}" up -d --wait nginx >/dev/null 2>&1
expect "nginx running" "running" "$(docker inspect -f '{{.State.Status}}' "$(svc_id nginx)")"
expect "nginx healthy" "healthy" "$(health nginx)"
expect "atom healthy" "healthy" "$(health atom)"
expect "el puerto web se publica solo en loopback" "127.0.0.1:$PORT" \
  "$(docker port "$(svc_id nginx)" 80/tcp | head -n1 | sed 's/^0\.0\.0\.0/ANY/')"
expect "único servicio con puerto publicado: nginx" "nginx" \
  "$(docker ps --filter label=com.docker.compose.project=archivo-historico --format '{{.Label "com.docker.compose.service"}} {{.Ports}}' | grep -- '->' | awk '{print $1}' | sort -u | tr '\n' ' ' | sed 's/ $//')"
expect "el host accede a Nginx (portada)" "200" "$(code "$URL/")"

echo "== B. Readiness de aplicación =="
scripts/web-ready.sh >/dev/null 2>&1 && ready=READY || ready=NOT_READY
expect "web-ready.sh: 200 + marcador AtoM" "READY" "$ready"
expect "un 200 estático de Nginx NO es READY (robots.txt sin marcador)" "0" \
  "$(curl -sS -D - -o /dev/null "$URL/robots.txt" | grep -ci '^set-cookie: atom_culture=' || true)"
expect "la portada lleva el marcador AtoM" "1" \
  "$(curl -sS -D - -o /dev/null "$URL/" | grep -ci '^set-cookie: atom_culture=' || true)"

echo "== C. FastCGI: la petición llega a PHP-FPM de atom =="
expect "la imagen nginx no contiene ningún .php" "0" "$(in_svc nginx sh -c 'find /atom/src -name "*.php" | wc -l')"
N1="$(curl -sS -D - -o /dev/null "$URL/" | grep -io "nonce-[0-9a-f]*" | head -n1)"
N2="$(curl -sS -D - -o /dev/null "$URL/" | grep -io "nonce-[0-9a-f]*" | head -n1)"
expect_ne "respuesta dinámica generada por PHP (nonce CSP distinto por petición)" "$N1" "$N2"
expect ".php directo denegado (config)" "404" "$(code "$URL/config/config.php")"
expect ".yml denegado" "404" "$(code "$URL/config/search.yml")"
expect "/private/ (X-Accel) no es accesible desde fuera" "404" "$(code "$URL/private/uploads/$MARK")"

echo "== D. Assets base =="
curl -sS "$URL/" -o "$TMP/home.html"
mapfile -t ASSETS < <(grep -Eo '(src|href)="/(dist|plugins|images|css|js|favicon)[^"]*"' "$TMP/home.html" | sed -E 's/^[a-z]+="//;s/"$//' | sort -u)
expect_ne "la portada referencia assets locales" "0" "${#ASSETS[@]}"
for a in "${ASSETS[@]}"; do
  expect "asset $a" "200" "$(code "$URL$a")"
  # Con el theme habilitado (reconcile), la portada referencia bundles que solo existen en `theme_dist` (montado en nginx,
  # no en atom): la fuente de verdad del fichero es entonces el propio nginx; el resto viene de la imagen atom.
  src=atom; in_svc atom test -e "/atom/src$a" 2>/dev/null || src=nginx
  expect "asset $a idéntico al de $src" "$(in_svc "$src" sha256sum "/atom/src$a" | awk '{print $1}')" \
    "$(curl -sS "$URL$a" | sha256sum | awk '{print $1}')"
done

echo "== E. Sesión / login (HTTP) =="
JAR="$TMP/jar"
curl -sS -c "$JAR" -b "$JAR" -o "$TMP/login.html" "$URL/user/login"
TOKEN="$(csrf "$TMP/login.html")"
expect_ne "el formulario de login trae token CSRF" "" "$TOKEN"
expect "anónimo antes del login" "no" "$(logged_in "$TMP/anon")"
SID_BEFORE="$(awk '$6=="symfony"{print $7}' "$JAR")"
expect "cookie de sesión symfony sin flag Secure sobre HTTP" "FALSE" "$(awk '$6=="symfony"{print $4}' "$JAR")"
# Controles negativos: sin CSRF válido o con clave errónea no hay sesión (no se desactiva ningún control).
login "$TMP/bad1" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "csrf-invalido" >/dev/null
expect "CSRF inválido no autentica" "no" "$(logged_in "$TMP/bad1")"
curl -sS -c "$TMP/bad2" -b "$TMP/bad2" -o "$TMP/l2.html" "$URL/user/login"
login "$TMP/bad2" "$ADMIN_EMAIL" "clave-incorrecta-$RUN" "$(csrf "$TMP/l2.html")" >/dev/null
expect "contraseña incorrecta no autentica" "no" "$(logged_in "$TMP/bad2")"
expect "login correcto redirige" "302" "$(login "$JAR" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "$TOKEN")"
expect "petición posterior conserva la autenticación" "yes" "$(logged_in "$JAR")"
expect "la sesión se regenera al autenticar" "1" "$([[ "$(awk '$6=="symfony"{print $7}' "$JAR")" != "$SID_BEFORE" ]] && echo 1 || echo 0)"
expect "una petición sin la cookie sigue anónima" "no" "$(logged_in "$TMP/other")"

echo "== F/G. Mounts de nginx =="
NID="$(svc_id nginx)"
expect "nginx: conf, uploads, downloads, theme_dist e images/ del theme (nunca el plugin completo)" \
  "bind /atom/src/plugins/arUnicaucaB5Plugin/images;bind /etc/nginx/nginx.conf;volume /atom/src/dist;volume /atom/src/downloads;volume /atom/src/uploads;" \
  "$(docker inspect -f '{{range .Mounts}}{{.Type}} {{.Destination}};{{end}}' "$NID" | tr ';' '\n' | sort | tr '\n' ';' | sed 's/^;//')"
expect "nginx: ningún mount es RW" "" "$(docker inspect -f '{{range .Mounts}}{{if .RW}}{{.Destination}} {{end}}{{end}}' "$NID")"
expect "nginx: sin bind sobre /atom/src" "" \
  "$(docker inspect -f '{{range .Mounts}}{{if and (eq .Type "bind") (eq .Destination "/atom/src")}}x{{end}}{{end}}' "$NID")"
expect "atom: los únicos binds son el plugin del theme y el entrypoint del proyecto (RO)" "/atom/src/plugins/arUnicaucaB5Plugin false;/project/scripts/runtime-config.sh false;" \
  "$(docker inspect -f '{{range .Mounts}}{{if eq .Type "bind"}}{{.Destination}} {{.RW}};{{end}}{{end}}' "$(svc_id atom)" | tr ';' '\n' | sort | tr '\n' ';' | sed 's/^;//')"
W_UP=0; W_DOWN=0
in_svc nginx sh -c "echo x > /atom/src/uploads/nginx-write-$RUN" 2>/dev/null || W_UP=$?
in_svc nginx sh -c "echo x > /atom/src/downloads/nginx-write-$RUN" 2>/dev/null || W_DOWN=$?
expect_ne "nginx no puede escribir en uploads" "0" "$W_UP"
expect_ne "nginx no puede escribir en downloads" "0" "$W_DOWN"

echo "== H. Persistencia y recreación =="
in_svc atom sh -c "echo up-$RUN > /atom/src/uploads/$MARK && echo down-$RUN > /atom/src/downloads/$MARK"
expect "Nginx sirve uploads (RO)" "up-$RUN" "$(curl -sS "$URL/uploads/$MARK")"
expect "Nginx sirve downloads (RO)" "down-$RUN" "$(curl -sS "$URL/downloads/$MARK")"
STATE_BEFORE="$("${COMPOSE[@]}" run --rm -T db-probe 2>/dev/null | head -n1)"

# atom caído: la web dinámica falla (502) y el estático de Nginx sigue; al volver atom (IP distinta) Nginx lo alcanza sin reiniciarse.
OLD_IP="$(ip_of atom)"
"${COMPOSE[@]}" rm -sf atom >/dev/null 2>&1
DEAD="$(code "$URL/" || true)"
expect "atom detenido: la web dinámica falla en Nginx (502/504; FastCGI real)" "gateway" "$([[ "$DEAD" == 502 || "$DEAD" == 504 ]] && echo gateway || echo "$DEAD")"
expect "atom detenido: el estático de Nginx sigue sirviendo" "200" "$(code "$URL/robots.txt")"
# El helper ocupa la IP liberada de atom con la imagen nginx del proyecto (sin imágenes externas flotantes).
docker run -d --rm --name "web-test-ipsquat-$RUN" --network archivo-historico_default --ip "$OLD_IP" \
  --entrypoint sleep archivo-historico/nginx:2.10.2 120 >/dev/null 2>&1 || true
expect "helper ocupa la IP anterior de atom" "$OLD_IP" \
  "$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "web-test-ipsquat-$RUN" 2>/dev/null || true)"
"${COMPOSE[@]}" up -d --no-deps --wait atom >/dev/null 2>&1
expect_ne "atom recreado con IP distinta" "$OLD_IP" "$(ip_of atom)"
WEB_READY_ATTEMPTS=10 WEB_READY_INTERVAL=2 scripts/web-ready.sh --wait >/dev/null 2>&1 && ready=READY || ready=NOT_READY
expect "Nginx alcanza al atom nuevo sin reiniciarse" "READY" "$ready"

# Recreación conjunta de nginx + atom (pasando por el gate de bootstrap).
OLD_NGINX="$(svc_id nginx)"
"${COMPOSE[@]}" rm -sf nginx atom >/dev/null 2>&1
"${COMPOSE[@]}" up -d --wait nginx >/dev/null 2>&1
expect_ne "nginx recreado (contenedor nuevo)" "$OLD_NGINX" "$(svc_id nginx)"
scripts/web-ready.sh --wait >/dev/null 2>&1 && ready=READY || ready=NOT_READY
expect "READY tras recrear nginx + atom" "READY" "$ready"
expect "uploads persiste" "up-$RUN" "$(curl -sS "$URL/uploads/$MARK")"
expect "downloads persiste" "down-$RUN" "$(curl -sS "$URL/downloads/$MARK")"
expect "BD compatible antes" "DB_COMPATIBLE" "$STATE_BEFORE"
expect "BD compatible después" "DB_COMPATIBLE" "$("${COMPOSE[@]}" run --rm -T db-probe 2>/dev/null | head -n1)"
curl -sS -c "$TMP/j3" -b "$TMP/j3" -o "$TMP/l3.html" "$URL/user/login"
login "$TMP/j3" "$ADMIN_EMAIL" "$ADMIN_PASSWORD" "$(csrf "$TMP/l3.html")" >/dev/null
expect "el usuario admin (BD) sigue pudiendo iniciar sesión" "yes" "$(logged_in "$TMP/j3")"

echo "== J. Upstream limpio =="
expect "upstream/atom sin cambios" "" "$(git -C upstream/atom status --porcelain)"

echo
if ((fails)); then echo "test-web: $fails fallo(s)"; exit 1; fi
echo "test-web: OK"
