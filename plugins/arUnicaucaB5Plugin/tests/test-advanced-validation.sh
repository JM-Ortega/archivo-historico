#!/usr/bin/env bash
# KAN-163: avisos de la búsqueda avanzada (criterios vacíos y rango de fechas). Prueba la función pura de js/advanced-search-validation.js;
# no necesita el runtime arriba. El enlace con el formulario real se comprueba en el navegador.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
P="$ROOT/plugins/arUnicaucaB5Plugin"
if command -v node >/dev/null; then node "$P/tests/advanced-validation.test.js"; else
  docker run --rm -v "$P:/p:ro" --entrypoint node archivo-historico/atom:2.10.2 /p/tests/advanced-validation.test.js; fi
echo "test-advanced-validation: OK"
