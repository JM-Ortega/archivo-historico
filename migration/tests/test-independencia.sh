#!/usr/bin/env bash
# Prueba de la migración de Independencia con un catálogo ficticio (migration/fixtures/catalogo-muestra.txt).
# Qué prueba: lectura del catálogo (etiquetas mal escritas, campos pegados), fechas, normalización de signaturas,
#   cuarentena de signaturas repetidas y forma del CSV de AtoM.
# Cómo se ejecuta: desde cualquier carpeta del repo, con Docker disponible (usa la imagen del servicio `atom`, sin tocar la BD).
# Requiere: la imagen archivo-historico/atom:2.10.2 ya construida (`docker compose up -d --build --wait`). Se usa `docker run`
#   y no `docker compose run` para no disparar un rebuild de la imagen.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="$(mktemp -d)"
trap 'rm -rf "$OUT"' EXIT

IMAGE="${ATOM_IMAGE:-archivo-historico/atom:2.10.2}"

run() {
  docker run --rm --user "$(id -u):$(id -g)" \
    -v "$ROOT/migration:/migration:ro" -v "$OUT:/out" --entrypoint python3 "$IMAGE" -I "$@"
}

run /migration/independencia.py --catalog /migration/fixtures/catalogo-muestra.txt --out /out
run /migration/tests/check_independencia.py /out
