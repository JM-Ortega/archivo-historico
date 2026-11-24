#!/usr/bin/env bash
# KAN-164 (CD-10): acceso por rol. Investigador (sin sesión), Catalogador y Administrador: qué pueden y qué NO pueden hacer.
#
#   ROLES_TEST_EDITOR_EMAIL=... ROLES_TEST_EDITOR_PASSWORD=... plugins/arUnicaucaB5Plugin/tests/test-access-roles.sh
#
# Las credenciales del catalogador de prueba NO van en el código: se pasan por entorno (están en la guía local del proyecto).
# Sin ellas, los casos del catalogador se omiten y se avisa. Credenciales del administrador: ATOM_ADMIN_EMAIL / ATOM_ADMIN_PASSWORD.
# Crea una ficha ZZ-PRUEBA y la borra al terminar. Requiere el runtime arriba (`docker compose up -d --wait`).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

docker compose exec -T atom php symfony cc >/dev/null

docker compose exec -T \
  -e CATALOGING_TEST_EMAIL="${ATOM_ADMIN_EMAIL:-admin@unicauca.edu.co}" \
  -e CATALOGING_TEST_PASSWORD="${ATOM_ADMIN_PASSWORD:-admin_dev_12345}" \
  -e ROLES_TEST_EDITOR_EMAIL="${ROLES_TEST_EDITOR_EMAIL:-}" \
  -e ROLES_TEST_EDITOR_PASSWORD="${ROLES_TEST_EDITOR_PASSWORD:-}" \
  atom php symfony tools:run plugins/arUnicaucaB5Plugin/tests/access-roles.php
