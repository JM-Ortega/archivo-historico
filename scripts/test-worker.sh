#!/usr/bin/env bash
# Prueba de integración del runtime `atom_worker` del Compose DEV: gate de bootstrap, baseline, mounts,
# configuración Gearman, liveness (proceso + registro real en Gearmand), consumo de un job inocuo,
# recreación y gate negativo.
#
#   scripts/test-worker.sh
#
# Usa el proyecto DEV real sin destruir estado (nunca `down -v`):
#   - "recrear" = `rm -sf atom_worker` + `up atom_worker`; atom/nginx/percona no se recrean (se comprueba);
#   - los artefactos (`worker-test-<id>`) se escriben en uploads/downloads y se borran al terminar;
#   - el job de prueba NO lleva `id` de QubitJob: el worker lo consume y lo rechaza
#     (`Required parameter not found for job: id`) antes de tocar la BD; no se crea ninguna fila ni fichero;
#   - el gate negativo apunta el bootstrap a una BD inexistente; no puede instalar (el usuario no tiene acceso).
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose)
RUN="$(head -c6 /dev/urandom | od -An -tx1 | tr -d ' \n')"
MARK="worker-test-$RUN"
UP=/atom/src/uploads
DOWN=/atom/src/downloads
FAILED_JOB_MSG='Job failed: Required parameter not found for job: id'
fails=0

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:70}"; else echo "FAIL  $1 -> ${3:0:300} (esperado ${2:0:300})"; fails=$((fails + 1)); fi
}
expect_ne() { # <descripción> <no esperado> <resultado>
  if [[ "$3" != "$2" ]]; then echo "PASS  $1 -> ${3:0:70}"; else echo "FAIL  $1 -> ${3:0:300} (no debía ser ${2:0:300})"; fails=$((fails + 1)); fi
}

svc_id() { "${COMPOSE[@]}" ps -aq "$1"; }
in_svc() { local s="$1"; shift; "${COMPOSE[@]}" exec -T "$s" "$@"; }
w_id() { svc_id atom_worker; }
w_status() { local id; id="$(w_id)"; if [[ -n "$id" ]]; then docker inspect -f '{{.State.Status}}' "$id"; else echo absent; fi; }
w_up() { "${COMPOSE[@]}" up -d --wait atom_worker >/dev/null 2>&1; }
w_down() { "${COMPOSE[@]}" rm -sf atom_worker >/dev/null 2>&1; }
ip_of() { docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$1"; }
job_count() { in_svc percona sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -N -e "SELECT COUNT(*) FROM job" 2>/dev/null'; }

# Respuesta del comando de administración `workers` de Gearmand.
gm_workers() {
  in_svc gearmand bash -c 'exec 3<>/dev/tcp/127.0.0.1/4730; printf "workers\n" >&3; while IFS= read -r -t 3 l <&3; do l="${l%$'"'"'\r'"'"'}"; [[ $l == . ]] && break; echo "$l"; done'
}
# Nº de funciones `<md5>-<ability>` registradas por conexiones desde <ip>.
gm_functions_from() { gm_workers | awk -v ip="$1" '$2 == ip { for (i = 5; i <= NF; i++) if ($i ~ /^[0-9a-f]{32}-/) n++ } END { print n + 0 }'; }

worker_log() { docker logs "$(w_id)" 2>&1; }
# Log de la ejecución actual (docker logs acumula las de reinicios anteriores del mismo contenedor).
worker_log_current() { local id; id="$(w_id)"; docker logs --since "$(docker inspect -f '{{.State.StartedAt}}' "$id")" "$id" 2>&1; }
# Nº de funciones registradas por conexiones desde <ip> cuyo nombre empieza por <prefijo> (getJobPrefix ya incluye el "-").
gm_prefixed_functions() { gm_workers | awk -v ip="$1" -v p="$2" '$2 == ip || ip == "*" { for (i = 5; i <= NF; i++) if (index($i, p) == 1) n++ } END { print n + 0 }'; }
count_failed_jobs() { worker_log | grep -c "$FAILED_JOB_MSG" || true; }

# Usa `atom` (RW sobre los mismos volúmenes), no el worker: funciona aunque el worker esté eliminado o unhealthy.
cleanup() {
  in_svc atom rm -f "$UP/$MARK" "$DOWN/$MARK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "== A. atom_worker forma parte del runtime por defecto =="
expect "sin profiles: atom_worker está en el runtime por defecto" "1" "$("${COMPOSE[@]}" config --services | grep -c '^atom_worker$')"
expect "atom_worker no requiere ningún profile" "0" "$("${COMPOSE[@]}" config --format json | grep -c '"profiles"' || true)"

echo "== Runtime =="
w_up
WID="$(w_id)"
ATOM_ID="$(svc_id atom)"
NGINX_ID="$(svc_id nginx)"
expect "atom_worker en ejecución" "running" "$(docker inspect -f '{{.State.Status}}' "$WID")"
expect "healthcheck del worker" "healthy" "$(docker inspect -f '{{.State.Health.Status}}' "$WID")"

echo "== B. Gate de bootstrap (contrato estructural en compose.yaml; el gate negativo de G lo demuestra) =="
expect "el bootstrap terminó con éxito" "0" "$(docker inspect -f '{{.State.ExitCode}}' "$(svc_id bootstrap)")"
expect "en el worker solo está el script del healthcheck" "worker-health.sh" "$(in_svc atom_worker ls /project/scripts)"

echo "== C. Baseline y comando =="
expect "misma imagen que atom" "$(docker inspect -f '{{.Image}}' "$ATOM_ID")" "$(docker inspect -f '{{.Image}}' "$WID")"
expect "imagen archivo-historico/atom:2.10.2" "archivo-historico/atom:2.10.2" "$(docker inspect -f '{{.Config.Image}}' "$WID")"
expect "entrypoint upstream conservado" '["docker/entrypoint.sh"]' "$(docker inspect -f '{{json .Config.Entrypoint}}' "$WID")"
expect "command: worker (atajo upstream)" '["worker"]' "$(docker inspect -f '{{json .Config.Cmd}}' "$WID")"
expect "comando real: php symfony jobs:worker (PID 1)" "php /atom/src/docker/../symfony jobs:worker" \
  "$(in_svc atom_worker sh -c "tr '\\0' ' ' </proc/1/cmdline | sed 's/ \$//'")"
expect "restart policy: on-failure" "on-failure" "$(docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' "$WID")"
expect "restart policy: máximo 5 reintentos" "5" "$(docker inspect -f '{{.HostConfig.RestartPolicy.MaximumRetryCount}}' "$WID")"
expect "sin puertos publicados" "" "$(docker port "$WID")"

echo "== D. Filesystem =="
expect "mounts = uploads RW, downloads RW, script de health RO y plugin del theme RO" \
  "bind /atom/src/plugins/arUnicaucaB5Plugin false;bind /project/scripts/worker-health.sh false;volume $DOWN true;volume $UP true;" \
  "$(docker inspect -f '{{range .Mounts}}{{.Type}} {{.Destination}} {{.RW}};{{end}}' "$WID" | tr ';' '\n' | sort | tr '\n' ';' | sed 's/^;//')"
expect "sin bind de /atom/src ni del checkout (solo el script de health y el plugin del theme)" "/atom/src/plugins/arUnicaucaB5Plugin" \
  "$(docker inspect -f '{{range .Mounts}}{{if eq .Type "bind"}}{{if ne .Destination "/project/scripts/worker-health.sh"}}{{.Destination}}{{end}}{{end}}{{end}}' "$WID")"
in_svc atom_worker sh -c "echo uploads-$RUN > $UP/$MARK && echo downloads-$RUN > $DOWN/$MARK"
expect "worker escribe uploads y atom lo ve (mismo volumen)" "uploads-$RUN" "$(in_svc atom cat "$UP/$MARK")"
expect "worker escribe downloads y atom lo ve (mismo volumen)" "downloads-$RUN" "$(in_svc atom cat "$DOWN/$MARK")"
expect "nginx lo ve en RO (uploads)" "uploads-$RUN" "$(in_svc nginx cat "$UP/$MARK")"

echo "== E. Configuración Gearman =="
expect "gearman.yml generado desde el entorno" "default: gearmand:4730" \
  "$(in_svc atom_worker sh -c "grep -o 'default: .*' /atom/src/apps/qubit/config/gearman.yml")"
PREFIX="$(in_svc atom_worker php <<'PHP'
<?php
require_once '/atom/src/config/ProjectConfiguration.class.php';
sfContext::createInstance(ProjectConfiguration::getApplicationConfiguration('qubit', 'worker', false));
echo json_encode(arGearman::getServers()), '|', QubitJob::getJobPrefix();
PHP
)"
expect "servidores efectivos de AtoM (gana el generado sobre el default 127.0.0.1)" '{"default":"gearmand:4730"}' "${PREFIX%%|*}"
PREFIX="${PREFIX##*|}"

echo "== F. Liveness =="
WIP="$(ip_of "$WID")"
N_ABILITIES="$(worker_log_current | grep -c 'New ability: ')"
expect "el worker registró abilities" "yes" "$([[ "$N_ABILITIES" -gt 0 ]] && echo yes || echo no)"
expect "Gearmand lista exactamente esas funciones para el worker" "$N_ABILITIES" "$(gm_functions_from "$WIP")"
expect "todas con el prefijo de la instalación" "$N_ABILITIES" \
  "$(gm_prefixed_functions "$WIP" "$PREFIX")"
expect "worker-health.sh: operativo" "0" "$(in_svc atom_worker bash /project/scripts/worker-health.sh >/dev/null 2>&1; echo $?)"
expect "worker-health.sh falla si Gearmand no es alcanzable" "1" \
  "$("${COMPOSE[@]}" exec -T -e ATOM_GEARMAND_HOST=nohost-gearmand:4730 atom_worker bash /project/scripts/worker-health.sh >/dev/null 2>&1; echo $?)"
expect "worker-health.sh falla sin proceso jobs:worker (contenedor sin worker)" "1" \
  "$("${COMPOSE[@]}" run --rm --no-deps -T atom_worker bash /project/scripts/worker-health.sh >/dev/null 2>&1; echo $?)"

echo "== F2. Consumo real de un job inocuo =="
JOBS_BEFORE="$(job_count)"
FAILED_BEFORE="$(count_failed_jobs)"
DOWN_LISTING_BEFORE="$(in_svc atom_worker ls -A "$DOWN" "$UP" | sort | tr '\n' ' ')"
HANDLE="$(in_svc atom_worker php <<'PHP'
<?php
require_once '/atom/src/config/ProjectConfiguration.class.php';
sfContext::createInstance(ProjectConfiguration::getApplicationConfiguration('qubit', 'worker', false));
// Mismo camino que QubitJob::runJob (Net_Gearman_Client, función <prefijo>-<ability>), pero sin `id`:
// el worker rechaza el job en arBaseJob::checkRequiredParameters, sin tocar la BD.
$fn = QubitJob::getJobPrefix().arGearman::getAbilities()[0];
echo (new Net_Gearman_Client(arGearman::getServers()))->{$fn}(['name' => 'worker-test']);
PHP
)"
expect "Gearmand aceptó el job (handle)" "H:" "${HANDLE:0:2}"
for _ in $(seq 1 15); do [[ "$(count_failed_jobs)" -gt "$FAILED_BEFORE" ]] && break; sleep 1; done
expect "el worker consumió el job (rechazo esperado en su log)" "$((FAILED_BEFORE + 1))" "$(count_failed_jobs)"
expect "sin efectos: ninguna fila nueva en la tabla job" "$JOBS_BEFORE" "$(job_count)"
expect "sin efectos: uploads/downloads sin cambios" "$DOWN_LISTING_BEFORE" "$(in_svc atom_worker ls -A "$DOWN" "$UP" | sort | tr '\n' ' ')"
expect "el worker sigue operativo" "0" "$(in_svc atom_worker bash /project/scripts/worker-health.sh >/dev/null 2>&1; echo $?)"

echo "== H. Recreación del worker =="
STATE_BEFORE="$("${COMPOSE[@]}" run --rm -T db-probe 2>/dev/null | head -n1)"
w_down
expect "worker eliminado" "" "$(w_id)"
expect "su registro en Gearmand desaparece con el proceso" "0" "$(gm_functions_from "$WIP")"
RECREATE_SINCE="$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)"
w_up
NEW_WID="$(w_id)"
expect_ne "contenedor nuevo" "$WID" "$NEW_WID"
expect "worker sano tras recrear" "healthy" "$(docker inspect -f '{{.State.Health.Status}}' "$NEW_WID")"
expect "registrado de nuevo en Gearmand" "$N_ABILITIES" "$(gm_functions_from "$(ip_of "$NEW_WID")")"
expect "uploads persiste" "uploads-$RUN" "$(in_svc atom_worker cat "$UP/$MARK")"
expect "downloads persiste" "downloads-$RUN" "$(in_svc atom_worker cat "$DOWN/$MARK")"
BOOT_LOG="$(docker logs --since "$RECREATE_SINCE" "$(svc_id bootstrap)" 2>&1)"
expect "bootstrap de la recreación: BD compatible, no se instala" "1" "$(grep -c 'bootstrap: BD compatible; no se instala' <<<"$BOOT_LOG" || true)"
expect "bootstrap de la recreación: tools:install no ejecutado" "0" "$(grep -c 'ejecutando tools:install' <<<"$BOOT_LOG" || true)"
expect "BD compatible antes" "DB_COMPATIBLE" "$STATE_BEFORE"
expect "BD compatible después" "DB_COMPATIBLE" "$("${COMPOSE[@]}" run --rm -T db-probe 2>/dev/null | head -n1)"
expect "instalación completa después (installation-check)" "0" \
  "$("${COMPOSE[@]}" run --rm --no-deps -T bootstrap php /project/scripts/installation-check.php >/dev/null 2>&1; echo $?)"
expect "atom no se recreó" "$ATOM_ID" "$(svc_id atom)"
expect "nginx no se recreó" "$NGINX_ID" "$(svc_id nginx)"

echo "== G. Gate negativo: bootstrap fallido → jobs:worker no opera =="
w_down
GATE_RC=0
ATOM_MYSQL_DSN="mysql:host=percona;port=3306;dbname=nodb$RUN;charset=utf8mb4" \
  BOOTSTRAP_WAIT_ATTEMPTS=2 BOOTSTRAP_WAIT_INTERVAL=1 \
  "${COMPOSE[@]}" up -d --wait atom_worker >/dev/null 2>&1 || GATE_RC=$?
expect_ne "up falla" "0" "$GATE_RC"
# Contrato: jobs:worker no está operativo (el estado exacto del contenedor es detalle de Compose).
expect_ne "atom_worker no está running" "running" "$(w_status)"
GATE_WID="$(w_id)"
expect "jobs:worker nunca arrancó (sin 'Running worker...')" "0" \
  "$([[ -n "$GATE_WID" ]] && { docker logs "$GATE_WID" 2>&1 | grep -c 'Running worker' || true; } || echo 0)"
expect "ningún worker de la instalación registrado en Gearmand" "0" \
  "$(gm_prefixed_functions '*' "$PREFIX")"
w_down
w_up
expect "worker restaurado tras el gate negativo" "healthy" "$(docker inspect -f '{{.State.Health.Status}}' "$(w_id)")"
expect "atom no se recreó (gate)" "$ATOM_ID" "$(svc_id atom)"
expect "nginx no se recreó (gate)" "$NGINX_ID" "$(svc_id nginx)"

echo "== I. Sin regresión web =="
WEB_RC=0
scripts/web-ready.sh --wait >/dev/null 2>&1 || WEB_RC=$?
expect "web-ready.sh: READY" "0" "$WEB_RC"

echo "== J. Limpieza =="
cleanup
expect "upstream/atom limpio" "" "$(git -C upstream/atom status --porcelain)"
expect "sin artefactos de prueba en los volúmenes" "0" \
  "$(in_svc atom_worker sh -c "ls -A $UP $DOWN | grep -c '^worker-test-' || true")"
expect "sin contenedores auxiliares (run)" "0" "$(docker ps -a --format '{{.Names}}' | grep -c '^archivo-historico-.*-run-' || true)"

echo
if ((fails)); then echo "test-worker: $fails fallo(s)"; exit 1; fi
echo "test-worker: OK"
