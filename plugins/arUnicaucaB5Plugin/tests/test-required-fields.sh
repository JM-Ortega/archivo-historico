#!/usr/bin/env bash
# KAN-164 (CD-02 y CD-09): campos obligatorios del formulario de catalogación (signatura en la unidad documental; título o signatura en
# la agrupación). Conduce el formulario real por HTTP y verifica por SQL que un guardado rechazado no crea ni cambia nada.
#
#   plugins/arUnicaucaB5Plugin/tests/test-required-fields.sh
#
# Crea descripciones con prefijo ZZ-PRUEBA y las borra al terminar. Requiere el runtime arriba (`docker compose up -d --wait`).
# Credenciales: ATOM_ADMIN_EMAIL / ATOM_ADMIN_PASSWORD (por defecto las del DEV).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

# Las clases nuevas del plugin entran en el autoload de Symfony tras limpiar su caché.
docker compose exec -T atom php symfony cc >/dev/null

docker compose exec -T \
  -e CATALOGING_TEST_EMAIL="${ATOM_ADMIN_EMAIL:-admin@unicauca.edu.co}" \
  -e CATALOGING_TEST_PASSWORD="${ATOM_ADMIN_PASSWORD:-admin_dev_12345}" \
  atom php symfony tools:run plugins/arUnicaucaB5Plugin/tests/required-fields.php
