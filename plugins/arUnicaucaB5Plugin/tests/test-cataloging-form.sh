#!/usr/bin/env bash
# Prueba de integración del formulario de catalogación (override de sfIsadPlugin/edit en arUnicaucaB5Plugin).
#
#   plugins/arUnicaucaB5Plugin/tests/test-cataloging-form.sh
#
# Ejecuta tests/cataloging-form.php dentro del servicio `atom` (`php symfony tools:run`): conduce el formulario real por HTTP
# contra `nginx` con el admin DEV y verifica por SQL la persistencia (Signatura, Nivel, Remitente/Destinatario multivalor,
# Contenido, Lugar, Fecha, Folios, Observaciones, título vacío), la edición de roles sin tocar otros eventos y la preservación
# de los campos adicionales. Crea sus propios actores, términos y descripciones de prueba y los borra al terminar.
# Requiere el runtime arriba (`docker compose up -d --wait`). Credenciales: ATOM_ADMIN_EMAIL / ATOM_ADMIN_PASSWORD.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

# Las clases nuevas del plugin (lib/, modules/) entran en el autoload de Symfony tras limpiar su caché.
docker compose exec -T atom php symfony cc >/dev/null

docker compose exec -T \
  -e CATALOGING_TEST_EMAIL="${ATOM_ADMIN_EMAIL:-admin@unicauca.edu.co}" \
  -e CATALOGING_TEST_PASSWORD="${ATOM_ADMIN_PASSWORD:-admin_dev_12345}" \
  atom php symfony tools:run plugins/arUnicaucaB5Plugin/tests/cataloging-form.php
