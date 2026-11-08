#!/usr/bin/env bash
# KAN-140: aviso de máximo de caracteres. Comprueba que servidor y script usan los mismos límites y que el script se comporta.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
P="$ROOT/plugins/arUnicaucaB5Plugin"
php_q=$(grep -oP 'MAX_QUERY_LENGTH = \K\d+' "$P/lib/arUnicaucaSearchInput.class.php")
php_t=$(grep -oP 'MAX_TERM_LENGTH = \K\d+' "$P/lib/arUnicaucaSearchInput.class.php")
js_q=$(grep -oP '"query" === name, max: \K\d+' "$P/js/search-limit.js")
js_t=$(grep -oP 'sq\\d\+\$/\.test\(name\), max: \K\d+' "$P/js/search-limit.js")
[[ "$php_q" == "$js_q" && "$php_t" == "$js_t" ]] || { echo "FALLA: límites distintos (php $php_q/$php_t, js $js_q/$js_t)"; exit 1; }
echo "ok: límites iguales en servidor y script ($php_q básica, $php_t por criterio)"
docker run --rm -v "$P:/p:ro" --entrypoint php archivo-historico/atom:2.10.2 /p/tests/search-input.php | tail -2
if command -v node >/dev/null; then node "$P/tests/search-limit.test.js"; else
  docker run --rm -v "$P:/p:ro" --entrypoint node archivo-historico/atom:2.10.2 /p/tests/search-limit.test.js; fi
echo "test-search-limit: OK"
