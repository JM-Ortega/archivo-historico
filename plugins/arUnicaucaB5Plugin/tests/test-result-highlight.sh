#!/usr/bin/env bash
# Prueba unitaria del resaltador de resultados (KAN-138) con el PHP de la imagen `atom`; no necesita el runtime arriba.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
docker run --rm -v "$ROOT/plugins/arUnicaucaB5Plugin:/p:ro" --entrypoint php archivo-historico/atom:2.10.2 /p/tests/result-highlight.php
echo "test-result-highlight: OK"
