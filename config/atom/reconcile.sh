#!/usr/bin/env bash
# RECONCILE DEV de AtoM (servicio `reconcile`): ejecuta, en orden, los pasos PROJECT-MANAGED pre-start del proyecto.
#
#   1. plugins requeridos habilitados      → config/atom/reconcile-plugins.sh
#   2. check_for_updates = 0               → config/atom/reconcile-check-for-updates.php (con `tools:run`)
#   3. términos propios de taxonomías      → config/atom/reconcile-terms.php (con `tools:run`)
#   4. require_strong_passwords = 1        → config/atom/reconcile-strong-passwords.php (con `tools:run`)
#   5. inherit_code_informationobject = 0  → config/atom/reconcile-reference-codes.php (con `tools:run`)
#   6. política de privacidad              → config/atom/reconcile-privacy-policy.php (con `tools:run`)
#   7. página «Acerca de la plataforma»    → config/atom/reconcile-about.php (con `tools:run`)
#
# No es un reconciliador genérico: cada paso es selectivo y se ejecuta solo si el anterior tuvo éxito; el primero que falla
# corta con su propio exit (plugins: 64/65/70/71/72; check_for_updates: 73-76; términos: 77-80; require_strong_passwords: 81-84; inherit_code_informationobject: 85-88; política de privacidad: 89-92; página «Acerca de»: 93-96) y `atom` / `atom_worker` no arrancan.
# Corre dentro del runtime AtoM, tras el bootstrap (BD instalada y compatible). Entorno: ATOM_SRC (/atom/src).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ATOM_SRC="${ATOM_SRC:-/atom/src}"

bash "$HERE/reconcile-plugins.sh"
cd "$ATOM_SRC"
php symfony tools:run "$HERE/reconcile-check-for-updates.php"
php symfony tools:run "$HERE/reconcile-terms.php"
php symfony tools:run "$HERE/reconcile-strong-passwords.php"
php symfony tools:run "$HERE/reconcile-reference-codes.php"
php symfony tools:run "$HERE/reconcile-privacy-policy.php"
php symfony tools:run "$HERE/reconcile-about.php"
