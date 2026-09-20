#!/usr/bin/env bash
# Healthcheck de `atom_worker`: el worker está operativo, no solo "el contenedor corre".
#
# Se ejecuta DENTRO del contenedor del worker (no usa el socket de Docker). Es de solo lectura: no crea
# jobs, no toca la BD y solo envía el comando de administración `workers` a Gearmand.
#
# OK  = existe un proceso `php symfony jobs:worker`
#       + Gearmand lista una conexión de ESE proceso (mismo IP de contenedor y client id `pid_<PID>_*`,
#         que es el id que Net_Gearman_Worker se asigna a sí mismo) con al menos una función registrada
#         (`<md5>-<ability>`, p. ej. `<md5>-arFindingAidJob`).
#
# La conexión a Gearmand pertenece al proceso: si `jobs:worker` muere o se desconecta (Gearmand
# reiniciado y aún sin reconectar), deja de aparecer y el check falla. El prefijo md5 depende de la
# instalación (QubitJob::getJobPrefix), por eso se exige la forma `<32 hex>-<nombre>`, no un nombre fijo.
#
# Entorno: ATOM_GEARMAND_HOST (host[:puerto]; el mismo que usa la configuración de AtoM).
# Salida: 0 operativo, 1 no operativo (el motivo se imprime en stderr).
set -euo pipefail

fail() { echo "worker-health: $*" >&2; exit 1; }

# PID del proceso jobs:worker (en el contenedor es el PID 1: el entrypoint hace exec).
worker_pid() {
  local d args
  for d in /proc/[0-9]*; do
    args="$(tr '\0' ' ' <"$d/cmdline" 2>/dev/null)" || continue
    [[ "$args" == *"symfony jobs:worker"* ]] && { echo "${d#/proc/}"; return 0; }
  done
  return 1
}

# Respuesta completa del comando `workers` (termina en una línea con solo ".").
gearman_workers() (
  local hp="${ATOM_GEARMAND_HOST:-gearmand:4730}" line
  [[ "$hp" == *:* ]] || hp+=":4730"
  exec 3<>"/dev/tcp/${hp%%:*}/${hp##*:}"
  printf 'workers\n' >&3
  while IFS= read -r -t 3 line <&3; do
    line="${line%$'\r'}"
    [[ "$line" == "." ]] && exit 0
    echo "$line"
  done
  exit 1
)

pid="$(worker_pid)" || fail "no hay proceso jobs:worker"
listing="$(gearman_workers)" || fail "Gearmand no responde al comando workers"

# Línea de `workers`: "<fd> <ip> <client-id> : <función> <función> ..."
for ip in $(hostname -i); do
  while IFS= read -r line; do
    [[ "$line" =~ ^[0-9]+\ $ip\ pid_${pid}_[^\ ]*\ :\ (.*)$ ]] || continue
    [[ " ${BASH_REMATCH[1]} " =~ \ [0-9a-f]{32}-[A-Za-z]+\  ]] && exit 0
  done <<<"$listing"
done
fail "jobs:worker (PID $pid) no está registrado en Gearmand con funciones"
