#!/usr/bin/env bash
# Prueba de la sanitización de búsqueda (KAN-98/KAN-99).
#
#   plugins/arUnicaucaB5Plugin/tests/test-search-input.sh
#
# 1) Casos unitarios de arUnicaucaSearchInput (PHP del servicio `atom`, sin base de datos).
# 2) Peticiones HTTP reales a `nginx` con entradas hostiles: ninguna debe terminar en error 5xx.
# Requiere el runtime arriba (`docker compose up -d --wait`).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

docker compose exec -T atom php symfony cc >/dev/null
docker compose exec -T atom php plugins/arUnicaucaB5Plugin/tests/search-input.php

BASE="http://localhost:8080/index.php/informationobject/browse"
fail=0
http() {
  local code
  code="$(curl -s -o /dev/null -w '%{http_code}' -g "$BASE?$1")"
  if [ "$code" -ge 500 ]; then echo "  FAIL HTTP $code  $2"; fail=1; else echo "  ok   HTTP $code  $2"; fi
}
echo "HTTP (sin 5xx)"
http 'query[]=x' 'query como arreglo'
http 'sq0[]=x&sf0=title' 'sq0 como arreglo'
http 'query=a%00b' 'byte NUL'
http "query=$(printf 'a%.0s' $(seq 1 3000))" 'término de 3000 caracteres'
http 'query=%22%3E%3Csvg%20onload%3Dalert(1)%3E' 'HTML/JS'
http 'query=(%22%20AND%20OR%20%5E%7E*' 'sintaxis rota'
http 'sq0=x&so0[]=or&startDate=zzz&rangeType[]=a' 'avanzada con basura'
exit "$fail"
