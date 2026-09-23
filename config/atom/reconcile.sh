#!/usr/bin/env bash
# RECONCILE DEV de AtoM (servicio `reconcile`): ejecuta, en orden, los pasos PROJECT-MANAGED pre-start del proyecto.
#
#   1. plugins requeridos habilitados      → config/atom/reconcile-plugins.sh
#   2. check_for_updates = 0               → config/atom/reconcile-check-for-updates.php (con `tools:run`)
#
# No es un reconciliador genérico: cada paso es selectivo y se ejecuta solo si el anterior tuvo éxito; el primero que falla
# corta con su propio exit (plugins: 64/65/70/71/72; check_for_updates: 73-76) y `atom` / `atom_worker` no arrancan.
# Corre dentro del runtime AtoM, tras el bootstrap (BD instalada y compatible). Entorno: ATOM_SRC (/atom/src).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ATOM_SRC="${ATOM_SRC:-/atom/src}"

bash "$HERE/reconcile-plugins.sh"
cd "$ATOM_SRC"
php symfony tools:run "$HERE/reconcile-check-for-updates.php"
