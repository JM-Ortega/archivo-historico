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
  local code body
  body="$(curl -s -w '\n%{http_code}' -g "$BASE?$1")"
  code="${body##*$'\n'}"
  # AtoM responde 200 también cuando Elasticsearch rechaza la consulta: la página de error se reconoce por su título.
  if [ "$code" -ge 500 ]; then echo "  FAIL HTTP $code  $2"; fail=1
  elif printf '%s' "$body" | grep -q 'Search error encountered'; then echo "  FAIL HTTP $code con «Search error encountered»  $2"; fail=1
  else echo "  ok   HTTP $code  $2"; fi
}
echo "HTTP (sin 5xx y sin «Search error encountered»)"
http 'query[]=x' 'query como arreglo'
http 'sq0[]=x&sf0=title' 'sq0 como arreglo'
http 'query=a%00b' 'byte NUL'
http "query=$(printf 'a%.0s' $(seq 1 3000))" 'término de 3000 caracteres'
http 'query=%22%3E%3Csvg%20onload%3Dalert(1)%3E' 'HTML/JS'
http 'query=(%22%20AND%20OR%20%5E%7E*' 'sintaxis rota'
http 'sq0=x&so0[]=or&startDate=zzz&rangeType[]=a' 'avanzada con basura'
# KAN-163: la barra y demás sintaxis mal formada ya no llegan a Elasticsearch.
http 'query=%3Cb%3Eprueba%3C%2Fb%3E' 'básica: <b>prueba</b> (la / abría una expresión regular)'
http 'query=a%2Fb' 'básica: a/b'
http 'query=%22prueba' 'básica: comilla sin pareja'
http 'query=prueba%20AND' 'básica: operador al final'
http 'query=()' 'básica: paréntesis vacíos'
http 'advSubmit=1&showAdvanced=1&sf0=scopeAndContent&sq0=1%2F2' 'avanzada: 1/2'
http 'advSubmit=1&showAdvanced=1&sf0=scopeAndContent&sq0=prueba%3A' 'avanzada: prueba:'
http 'advSubmit=1&showAdvanced=1&sf0=scopeAndContent&sq0=%3Cscript%3Ealert(1)%3C%2Fscript%3E' 'avanzada: <script>alert(1)</script>'

# KAN-163 (revisión de código): comprobar RESULTADOS, no solo que no haya error. Son igualdades que valen con cualquier catálogo.
cuenta() {
  curl -s -g "$BASE?$1" | grep -oE 'Mostrando [0-9.]+ resultados|No se han encontrado resultados' | head -1 | sed -E 's/Mostrando ([0-9.]+) resultados/\1/; s/No se han encontrado resultados/0/; s/\.//g'
}
igual() {
  local a b
  a="$(cuenta "$1")"; b="$(cuenta "$2")"
  if [ -n "$a" ] && [ "$a" = "$b" ]; then echo "  ok   $3 ($a = $b)"; else echo "  FAIL $3 ($a ≠ $b)"; fail=1; fi
}
AV='advSubmit=1&showAdvanced=1&topLod=0&sf0=scopeAndContent&sq0='
echo "Resultados (igualdades)"
total="$(cuenta 'topLod=0')"
con="$(cuenta "${AV}carta")"; sin="$(cuenta "${AV}NOT%20carta")"
if [ -n "$total" ] && [ "$((con + sin))" = "$total" ]; then echo "  ok   «x» + «NOT x» suma todas las fichas ($con + $sin = $total)"; else echo "  FAIL «x» + «NOT x» no suma el total ($con + $sin ≠ $total)"; fail=1; fi
igual "${AV}carta%20OR%20oficio" "${AV}(carta%20OR%20oficio)" 'un grupo con paréntesis no cambia el resultado'
igual "${AV}carta%20OR%20oficio" "${AV}%22carta%22%20OR%20%22oficio%22" 'dos frases con OR = dos palabras con OR'
igual "${AV}carta%20OR%20oficio" "${AV}(%22carta%22%20OR%20%22oficio%22)" 'dos frases agrupadas = dos palabras con OR'
igual "${AV}oficio%20AND%20(carta%20OR%20acta)" "${AV}oficio%20AND%20(%22carta%22%20OR%20%22acta%22)" 'la agrupación con frases se conserva'
exit "$fail"
