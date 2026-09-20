#!/usr/bin/env bash
# E2E de checkout limpio + RESET DEV real, sobre un entorno DESECHABLE y AISLADO:
#
#   git clone + submodule → `docker compose up -d --wait` (build automático + runtime completo) → fresh install
#   → READY + login + worker
#   → RESET DEV (`down -v`) → ausencia del estado anterior → segundo fresh install → READY + worker
#
#   scripts/test-fresh-e2e.sh
#
# Qué ocupa (todo propio y desechable):
#   - un directorio temporal ($TMPDIR o /tmp) con un clon nuevo de ESTE repo en el commit E2E_REF (por defecto
#     HEAD) y `git submodule update --init --recursive` (requiere red hacia la URL de .gitmodules);
#   - un proyecto Compose `archivo-historico-e2e-<id>` (`-p`, que tiene precedencia sobre el `name:` fijo del
#     compose; sin él el checkout compartiría contenedores/volúmenes/red con la instancia DEV normal);
#   - un puerto loopback libre (ATOM_WEB_PORT);
#   - imágenes propias `archivo-historico-e2e-<id>/{atom,nginx}:2.10.2`: el compose fija los tags
#     `archivo-historico/{atom,nginx}:2.10.2` con independencia del proyecto, y un build desde otro checkout los
#     reasignaría a imágenes nuevas (la DEV seguiría corriendo las viejas, pero su tag cambiaría). Un override
#     generado FUERA del checkout (que sigue siendo el commit exacto) renombra solo `image:`. Por eso este script
#     mantiene la selección explícita `-f compose.yaml -f <override> -p <proyecto>` (un desarrollador normal no la necesita).
#
# Contrato del primer arranque que se demuestra aquí: un checkout sin imágenes propias arranca con UN solo comando,
# `docker compose up -d --wait` (Compose construye lo que falta), sin `docker compose build` previo.
#
# La instancia DEV normal (proyecto `archivo-historico`) solo se OBSERVA (lectura, filtro exacto por label del
# proyecto): se comprueba que sus contenedores, volúmenes, red e imágenes quedan idénticos. Antes del primer
# `down -v` se demuestra que ningún recurso E2E coincide con los de la DEV; si no, STOP.
#
# Limpieza (trap): `down -v` del proyecto E2E, borrado de sus imágenes (tag exacto) y del directorio temporal. Nunca `docker system/volume prune`.
# E2E_KEEP=1 conserva el directorio y el proyecto (para depurar); la limpieza manual se imprime al final.
#
# Requisitos del host: git, Docker y Docker Compose (+ curl, ya usado por web-ready.sh).
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
REF="$(git -C "$SRC" rev-parse "${E2E_REF:-HEAD}")"
DEV_PROJECT=archivo-historico # contrato deliberado de la instancia DEV normal (name: de compose.yaml)
PROJECT="archivo-historico-e2e-$(head -c4 /dev/urandom | od -An -tx1 | tr -d ' \n')"
RUN="${PROJECT##*-}"
ADMIN_EMAIL="${ATOM_ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ATOM_ADMIN_PASSWORD:-admin_dev_12345}"
DEV_IMAGES=(archivo-historico/atom:2.10.2 archivo-historico/nginx:2.10.2)
E2E_ATOM_IMAGE="$PROJECT/atom:2.10.2"
E2E_NGINX_IMAGE="$PROJECT/nginx:2.10.2"
fails=0
OWNED=0
TMP=""
CK=""
DC=()

# El entorno del usuario no debe alterar la selección de proyecto/fichero/perfil.
unset COMPOSE_PROJECT_NAME COMPOSE_FILE COMPOSE_PROFILES

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:90}"; else echo "FAIL  $1 -> ${3:0:300} (esperado ${2:0:300})"; fails=$((fails + 1)); fi
}
expect_ne() { # <descripción> <no esperado> <resultado>
  if [[ "$3" != "$2" ]]; then echo "PASS  $1 -> ${3:0:90}"; else echo "FAIL  $1 -> ${3:0:300} (no debía ser ${2:0:300})"; fails=$((fails + 1)); fi
}
die() { echo "STOP  $*" >&2; exit 1; }
say() { echo; echo "== $* =="; }

label_of() { echo "label=com.docker.compose.project=$1"; }
svc_id() { "${DC[@]}" ps -aq "$1"; }
in_svc() { local s="$1"; shift; "${DC[@]}" exec -T "$s" "$@"; }
health() { docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$(svc_id "$1")"; }
csrf() { grep -o 'name="_csrf_token" value="[0-9a-f]*"' "$1" | head -n1 | sed 's/.*value="//;s/"$//' || true; }

# Recursos (solo lectura) de un proyecto Compose, por filtro exacto de label.
project_resources() { # <proyecto>
  local p="$1" ids
  ids="$(docker ps -aq --no-trunc --filter "$(label_of "$p")" | sort)"
  echo "containers:"
  # shellcheck disable=SC2086
  [[ -z "$ids" ]] || docker inspect -f '  {{.Id}} {{.Name}} {{.State.Status}} {{.State.StartedAt}} {{.RestartCount}}' $ids
  echo "volumes:"
  docker volume ls -q --filter "$(label_of "$p")" | sort | while read -r v; do
    [[ -z "$v" ]] || docker volume inspect -f '  {{.Name}} {{.CreatedAt}}' "$v"
  done
  echo "networks:"
  docker network ls --no-trunc --filter "$(label_of "$p")" --format '  {{.ID}} {{.Name}}' | sort
}
image_ids() { for i in "${DEV_IMAGES[@]}"; do docker image inspect -f '{{index .RepoTags 0}} {{.Id}}' "$i" 2>/dev/null || echo "$i ausente"; done; }

port_in_use() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }
pick_port() {
  local p
  for _ in $(seq 1 50); do p=$((18000 + RANDOM % 2000)); port_in_use "$p" || { echo "$p"; return; }; done
  return 1
}

cleanup() {
  local rc=$?
  trap - EXIT INT TERM
  if ((rc != 0)) && ((${#DC[@]})) && ((OWNED)); then
    echo "--- logs del proyecto E2E $PROJECT (fallo) ---" >&2
    (cd "$CK" && "${DC[@]}" logs --no-color --tail=60 2>&1 | tail -n 250) >&2 || true
  fi
  if [[ -n "${E2E_KEEP:-}" ]]; then
    echo "E2E_KEEP: conservado $TMP y el proyecto $PROJECT. Limpieza manual:"
    echo "  (cd $CK && docker compose -f compose.yaml -f $TMP/e2e-images.yaml -p $PROJECT down -v) && docker rmi $E2E_ATOM_IMAGE $E2E_NGINX_IMAGE && rm -rf $TMP"
    exit "$rc"
  fi
  # Solo se elimina lo que este script posee: proyecto E2E (probado aislado) y su directorio temporal.
  if ((OWNED)) && [[ "$PROJECT" == archivo-historico-e2e-* && -d "$CK" ]]; then
    (cd "$CK" && "${DC[@]}" down -v >/dev/null 2>&1) || true
    docker rmi "$E2E_ATOM_IMAGE" "$E2E_NGINX_IMAGE" >/dev/null 2>&1 || true
  fi
  [[ -n "$TMP" && "$TMP" == */archivo-historico-e2e.* ]] && rm -rf "$TMP"
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

for tool in git docker curl; do command -v "$tool" >/dev/null || die "falta $tool"; done
docker compose version >/dev/null 2>&1 || die "falta Docker Compose"

say "1. Checkout limpio (git clone + submodule)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/archivo-historico-e2e.XXXXXX")"
CK="$TMP/checkout"
git clone --quiet --no-checkout --no-hardlinks "$SRC" "$CK"
git -C "$CK" checkout --quiet --detach "$REF"
git -C "$CK" submodule update --init --recursive >"$TMP/submodule.log" 2>&1 || { cat "$TMP/submodule.log" >&2; die "git submodule update falló"; }
PIN="$(git -C "$CK" ls-tree HEAD upstream/atom | awk '{print $3}')"
expect "HEAD del checkout = $REF" "$REF" "$(git -C "$CK" rev-parse HEAD)"
expect "submodule = commit fijado por el superproyecto" "$PIN" "$(git -C "$CK/upstream/atom" rev-parse HEAD)"
expect "checkout limpio" "" "$(git -C "$CK" status --porcelain)"
expect "upstream/atom limpio" "" "$(git -C "$CK/upstream/atom" status --porcelain)"
expect "el checkout no es el repo canónico" "no" "$([[ "$CK" == "$SRC"* ]] && echo si || echo no)"

say "2. Aislamiento (ANTES de cualquier operación destructiva)"
PORT="$(pick_port)" || die "no hay puerto loopback libre"
export ATOM_WEB_PORT="$PORT"
cat >"$TMP/e2e-images.yaml" <<YAML
services:
  db-probe: { image: $E2E_ATOM_IMAGE }
  bootstrap: { image: $E2E_ATOM_IMAGE }
  atom: { image: $E2E_ATOM_IMAGE }
  atom_worker: { image: $E2E_ATOM_IMAGE }
  nginx: { image: $E2E_NGINX_IMAGE }
YAML
DC=(docker compose -f compose.yaml -f "$TMP/e2e-images.yaml" -p "$PROJECT")
cd "$CK"
expect "las imágenes E2E aún no existen" "0" "$(docker image ls -q "$PROJECT/*" | wc -l)"
expect "el compose fija el proyecto DEV normal" "name: $DEV_PROJECT" "$(grep '^name:' compose.yaml)"
expect "el proyecto efectivo es el E2E (-p gana al name: del compose)" "$PROJECT" \
  "$("${DC[@]}" config --format json | grep -oE '^  "name": "[^"]+"' | sed -E 's/.*: "//;s/"$//')"
[[ "$PROJECT" != "$DEV_PROJECT" && "$PROJECT" == archivo-historico-e2e-* ]] || die "nombre de proyecto E2E no válido"
expect "compose.dev.yaml ya no existe en el checkout" "no" "$([[ -e compose.dev.yaml ]] && echo si || echo no)"
expect "runtime completo por defecto (sin profiles)" "atom atom_worker bootstrap elasticsearch gearmand memcached nginx percona" \
  "$("${DC[@]}" config --services | sort | tr '\n' ' ' | sed 's/ $//')"
expect "profile tools: añade solo db-probe" "db-probe" \
  "$(comm -13 <("${DC[@]}" config --services | sort) <("${DC[@]}" --profile tools config --services | sort) | tr '\n' ' ' | sed 's/ $//')"
expect "puerto E2E distinto del de la DEV (8080)" "yes" "$([[ "$PORT" != 8080 ]] && echo yes || echo no)"
expect "puerto E2E libre" "free" "$(port_in_use "$PORT" && echo busy || echo free)"
expect "el E2E no tiene recursos previos" "0" "$(docker ps -aq --filter "$(label_of "$PROJECT")" | wc -l)"

# Nombres que Compose creará (proyecto, red y volúmenes) frente a los existentes de la DEV.
mapfile -t planned < <("${DC[@]}" config --format json | grep -oE '^ +"name": "[^"]+"' | sed -E 's/.*: "//;s/"$//')
dev_names="$(docker ps -a --filter "$(label_of "$DEV_PROJECT")" --format '{{.Names}}'; \
             docker volume ls -q --filter "$(label_of "$DEV_PROJECT")"; \
             docker network ls --filter "$(label_of "$DEV_PROJECT")" --format '{{.Name}}')"
collisions=0
for n in "${planned[@]}"; do
  [[ "$n" == "$PROJECT" || "$n" == "${PROJECT}_"* ]] || { echo "FAIL  nombre planificado fuera del proyecto E2E: $n"; collisions=$((collisions + 1)); }
  grep -qxF -- "$n" <<<"$dev_names" && { echo "FAIL  el nombre $n existe en la DEV"; collisions=$((collisions + 1)); }
done
((${#planned[@]} == 6)) || { echo "FAIL  se esperaban 6 nombres planificados (proyecto, red, 4 volúmenes), hay ${#planned[@]}"; collisions=$((collisions + 1)); }
((collisions == 0)) || die "el aislamiento del proyecto E2E no está garantizado"
echo "PASS  nombres E2E planificados sin colisión con la DEV: ${planned[*]}"
OWNED=1

DEV_BEFORE="$(project_resources "$DEV_PROJECT")"
DEV_IMAGES_BEFORE="$(image_ids)"
echo "DEV normal (solo observada):"; echo "$DEV_BEFORE" | sed 's/^/  | /'

# Verifica un arranque completo de fresh install. <n> = 1 (primero) o 2 (tras el RESET).
verify_fresh_install() {
  local n="$1" blog bid
  bid="$(svc_id bootstrap)"
  blog="$(docker logs "$bid" 2>&1)"
  expect "[$n] DB inicial = DB_FRESH" "bootstrap: probe: DB_FRESH (exit 10)" "$(grep -m1 'bootstrap: probe:' <<<"$blog")"
  expect "[$n] tools:install ejecutado exactamente una vez" "1" "$(grep -c 'ejecutando tools:install (una sola vez)' <<<"$blog" || true)"
  expect "[$n] post-probe = DB_COMPATIBLE" "1" "$(grep -c 'bootstrap: post-probe: DB_COMPATIBLE' <<<"$blog" || true)"
  expect "[$n] bootstrap: instalación completa verificada" "1" "$(grep -c 'instalación completa verificada' <<<"$blog" || true)"
  expect "[$n] bootstrap exit 0" "0" "$(docker inspect -f '{{.State.ExitCode}}' "$bid")"
  expect "[$n] DB final (db-probe independiente)" "DB_COMPATIBLE" "$("${DC[@]}" run --rm -T --no-deps db-probe 2>/dev/null || true)"
  expect "[$n] instalación (installation-check independiente)" "INSTALL_COMPLETE" \
    "$("${DC[@]}" run --rm -T --no-deps --entrypoint php bootstrap /project/scripts/installation-check.php 2>/dev/null || true)"
  expect "[$n] atom/atom_worker/nginx corren imágenes E2E propias" "$E2E_ATOM_IMAGE $E2E_ATOM_IMAGE $E2E_NGINX_IMAGE" \
    "$(for s in atom atom_worker nginx; do docker inspect -f '{{.Config.Image}}' "$(svc_id "$s")"; done | tr '\n' ' ' | sed 's/ $//')"
  for s in percona elasticsearch memcached gearmand atom nginx atom_worker; do expect "[$n] $s healthy" "healthy" "$(health "$s")"; done
  expect "[$n] web READY" "READY" "$(ATOM_WEB_URL="http://127.0.0.1:$PORT" "$CK/scripts/web-ready.sh" --wait 2>/dev/null | sed -E 's/^web-ready: (READY).*/\1/')"
  expect "[$n] único servicio publicado: nginx en 127.0.0.1:$PORT" "nginx 127.0.0.1:$PORT->80/tcp" \
    "$(docker ps --filter "$(label_of "$PROJECT")" --format '{{.Label "com.docker.compose.service"}} {{.Ports}}' | grep -- '->' | sed 's/, /,/g' | sort | tr '\n' ' ' | sed 's/ $//')"

  local wid gm
  wid="$(svc_id atom_worker)"
  expect "[$n] worker sin reinicios" "0" "$(docker inspect -f '{{.RestartCount}}' "$wid")"
  expect "[$n] worker-health.sh (proceso + registro en Gearmand)" "0" "$(in_svc atom_worker bash /project/scripts/worker-health.sh >/dev/null 2>&1; echo $?)"
  gm="$(in_svc gearmand bash -c 'exec 3<>/dev/tcp/127.0.0.1/4730; printf "workers\n" >&3; while IFS= read -r -t 3 l <&3; do l="${l%$'"'"'\r'"'"'}"; [[ $l == . ]] && break; echo "$l"; done')"
  expect_ne "[$n] Gearmand lista funciones <md5>-<ability> registradas" "0" "$(awk '{ for (i = 4; i <= NF; i++) if ($i ~ /^[0-9a-f]{32}-/) c++ } END { print c + 0 }' <<<"$gm")"
}

login_smoke() { # <n>
  local n="$1" jar="$TMP/jar-$1" url="http://127.0.0.1:$PORT" token
  curl -sS -c "$jar" -b "$jar" -o "$TMP/login-$n.html" "$url/user/login"
  token="$(csrf "$TMP/login-$n.html")"
  expect_ne "[$n] el formulario de login trae token CSRF" "" "$token"
  expect "[$n] anónimo antes del login" "no" "$(curl -sS -c "$jar" -b "$jar" "$url/" | grep -q 'user/logout' && echo yes || echo no)"
  expect "[$n] POST credenciales DEV redirige (302)" "302" "$(curl -sS -o /dev/null -w '%{http_code}' -c "$jar" -b "$jar" \
    --data-urlencode "_csrf_token=$token" --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "password=$ADMIN_PASSWORD" \
    --data-urlencode next= "$url/index.php/user/login")"
  expect "[$n] petición posterior autenticada" "yes" "$(curl -sS -c "$jar" -b "$jar" "$url/" | grep -q 'user/logout' && echo yes || echo no)"
}

say "3. Primer arranque en UN solo comando: up -d --wait (sin build previo)"
expect "sin imágenes propias antes del arranque" "0" "$(docker image ls --format '{{.Repository}}:{{.Tag}}' | grep -cxF -e "$E2E_ATOM_IMAGE" -e "$E2E_NGINX_IMAGE" || true)"
"${DC[@]}" up -d --wait --wait-timeout 900 >"$TMP/up1.log" 2>&1 || { tail -n 40 "$TMP/up1.log" >&2; die "primer up falló"; }
expect "Compose construyó las imágenes E2E propias (build automático)" "2" "$(docker image ls --format '{{.Repository}}:{{.Tag}}' | grep -cxF -e "$E2E_ATOM_IMAGE" -e "$E2E_NGINX_IMAGE" || true)"
expect "el arranque no alteró los tags de imagen de la DEV" "$DEV_IMAGES_BEFORE" "$(image_ids)"
verify_fresh_install 1
login_smoke 1

say "5. Estado antes del RESET (marcadores + recursos E2E)"
MARK="e2e-marker-$RUN"
in_svc atom sh -c 'printf %s "$0" >"$1"' "$MARK" "/atom/src/uploads/$MARK"
in_svc atom sh -c 'printf %s "$0" >"$1"' "$MARK" "/atom/src/downloads/$MARK"
# Marcador de BD: una base de datos aparte (no toca el schema ni los datos de AtoM); vive en percona_data.
in_svc percona sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE \`e2e_marker_$0\`"' "$RUN" 2>/dev/null
expect "marcador uploads_data" "$MARK" "$(in_svc atom cat "/atom/src/uploads/$MARK")"
expect "marcador downloads_data" "$MARK" "$(in_svc atom cat "/atom/src/downloads/$MARK")"
expect "marcador BD" "e2e_marker_$RUN" "$(in_svc percona sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SHOW DATABASES LIKE \"e2e_marker_$0\""' "$RUN" 2>/dev/null)"
E2E_BEFORE="$(project_resources "$PROJECT")"
echo "recursos E2E antes del RESET:"; echo "$E2E_BEFORE" | sed 's/^/  | /'
expect "E2E: 8 contenedores (bootstrap incluido)" "8" "$(docker ps -aq --filter "$(label_of "$PROJECT")" | wc -l)"
expect "E2E: 4 volúmenes propios" "4" "$(docker volume ls -q --filter "$(label_of "$PROJECT")" | wc -l)"
expect "E2E: red propia" "${PROJECT}_default" "$(docker network ls --filter "$(label_of "$PROJECT")" --format '{{.Name}}')"
expect "la DEV no cambió durante el primer arranque" "$DEV_BEFORE" "$(project_resources "$DEV_PROJECT")"

# Parada normal: ni `stop` ni `down` (sin -v) borran estado, y el rearranque no reinstala.
markers_present() { # <n> → comprueba los tres marcadores
  expect "[$1] marcador uploads_data conservado" "$MARK" "$(in_svc atom cat "/atom/src/uploads/$MARK")"
  expect "[$1] marcador downloads_data conservado" "$MARK" "$(in_svc atom cat "/atom/src/downloads/$MARK")"
  expect "[$1] marcador BD conservado" "e2e_marker_$RUN" "$(in_svc percona sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SHOW DATABASES LIKE \"e2e_marker_$0\""' "$RUN" 2>/dev/null)"
}
restart_cycle() { # <etiqueta> <installs esperados en el log del bootstrap> <comando de parada...>
  # `stop` conserva el contenedor bootstrap (su log acumula la instalación inicial: 1); `down` lo elimina (log nuevo: 0).
  local label="$1" installs="$2"; shift 2
  say "5b. Parada normal: $label + up -d --wait (sin borrar datos)"
  "${DC[@]}" "$@" >/dev/null 2>&1 || die "$label falló"
  expect "[$label] volúmenes conservados" "4" "$(docker volume ls -q --filter "$(label_of "$PROJECT")" | wc -l)"
  "${DC[@]}" up -d --wait --wait-timeout 900 >"$TMP/up-$label.log" 2>&1 || { tail -n 40 "$TMP/up-$label.log" >&2; die "up tras $label falló"; }
  local blog; blog="$(docker logs "$(svc_id bootstrap)" 2>&1)"
  expect "[$label] percona_data no se recreó" "$PERCONA_VOL_BEFORE" "$(docker volume inspect -f '{{.CreatedAt}}' "${PROJECT}_percona_data")"
  expect "[$label] el bootstrap no reinstala (ejecuciones de tools:install en su log)" "$installs" "$(grep -c 'ejecutando tools:install (una sola vez)' <<<"$blog" || true)"
  expect "[$label] bootstrap ve una BD compatible" "1" "$(grep -c 'BD compatible; no se instala' <<<"$blog" || true)"
  for s in percona elasticsearch memcached gearmand atom nginx atom_worker; do expect "[$label] $s healthy" "healthy" "$(health "$s")"; done
  expect "[$label] web READY" "READY" "$(ATOM_WEB_URL="http://127.0.0.1:$PORT" "$CK/scripts/web-ready.sh" --wait 2>/dev/null | sed -E 's/^web-ready: (READY).*/\1/')"
  expect "[$label] worker-health.sh" "0" "$(in_svc atom_worker bash /project/scripts/worker-health.sh >/dev/null 2>&1; echo $?)"
  markers_present "$label"
}
PERCONA_VOL_BEFORE="$(docker volume inspect -f '{{.CreatedAt}}' "${PROJECT}_percona_data")"
restart_cycle stop 1 stop
restart_cycle down 0 down
PERCONA_CID_BEFORE="$(svc_id percona)"

say "6. RESET DEV destructivo (solo el proyecto E2E)"
echo "\$ docker compose -p $PROJECT down -v   (con el override de imágenes E2E)"
"${DC[@]}" down -v >"$TMP/reset.log" 2>&1 || { cat "$TMP/reset.log" >&2; die "down -v falló"; }
expect "E2E: sin contenedores" "0" "$(docker ps -aq --filter "$(label_of "$PROJECT")" | wc -l)"
expect "E2E: sin volúmenes" "0" "$(docker volume ls -q --filter "$(label_of "$PROJECT")" | wc -l)"
expect "E2E: sin red" "0" "$(docker network ls -q --filter "$(label_of "$PROJECT")" | wc -l)"
for v in percona_data elasticsearch_data uploads_data downloads_data; do
  expect "E2E: volumen ${PROJECT}_$v eliminado" "absent" "$(docker volume inspect "${PROJECT}_$v" >/dev/null 2>&1 && echo present || echo absent)"
done
expect "DEV normal intacta tras el RESET" "$DEV_BEFORE" "$(project_resources "$DEV_PROJECT")"
expect "imágenes DEV intactas tras el RESET" "$DEV_IMAGES_BEFORE" "$(image_ids)"
expect "el checkout E2E sigue en su sitio" "yes" "$([[ -f "$CK/compose.yaml" ]] && echo yes || echo no)"

say "7. Segundo arranque sobre el MISMO checkout (up -d --wait, sin build)"
"${DC[@]}" up -d --wait --wait-timeout 900 >"$TMP/up2.log" 2>&1 || { tail -n 40 "$TMP/up2.log" >&2; die "segundo up falló"; }
verify_fresh_install 2
login_smoke 2
expect "[2] marcador uploads_data ausente" "absent" "$(in_svc atom test -e "/atom/src/uploads/$MARK" && echo present || echo absent)"
expect "[2] marcador downloads_data ausente" "absent" "$(in_svc atom test -e "/atom/src/downloads/$MARK" && echo present || echo absent)"
expect "[2] marcador BD ausente" "" "$(in_svc percona sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SHOW DATABASES LIKE \"e2e_marker_$0\""' "$RUN" 2>/dev/null)"
expect_ne "[2] percona_data es un volumen nuevo (CreatedAt distinto)" "$PERCONA_VOL_BEFORE" "$(docker volume inspect -f '{{.CreatedAt}}' "${PROJECT}_percona_data")"
expect_ne "[2] contenedor percona nuevo (ID distinto)" "$PERCONA_CID_BEFORE" "$(svc_id percona)"

say "8. La DEV normal, al final"
expect "DEV normal intacta al terminar" "$DEV_BEFORE" "$(project_resources "$DEV_PROJECT")"
expect "imágenes DEV intactas al terminar" "$DEV_IMAGES_BEFORE" "$(image_ids)"

echo
if ((fails > 0)); then echo "RESULT: FAIL ($fails comprobaciones)"; exit 1; fi
echo "RESULT: PASS (proyecto $PROJECT, puerto $PORT, checkout $REF)"
