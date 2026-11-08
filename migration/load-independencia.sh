#!/usr/bin/env bash
# Carga de la Sección Independencia en AtoM DEV, en orden y con parada al primer error.
#   uso:  migration/load-independencia.sh load        # reconcile v2 -> comprobar términos -> check-import -> import -> search:populate
#         migration/load-independencia.sh rollback    # borra el fondo cargado (por slug verificado)
# Requiere el runtime arriba (docker compose up -d) y migration/local/independencia/atom-independencia.csv ya generado
# (ver migration/README.md). No modifica archivos de otros: usa la copia reconcile-terms-v2-asunto.php.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
ATOM=${ATOM_CONTAINER:-archivo-historico-atom-1}
DB=${DB_CONTAINER:-archivo-historico-percona-1}
CSV=migration/local/independencia/atom-independencia.csv
SLUG=${FOND_SLUG:-fondo-antiguo-archivo-central-del-cauca}
EXPECTED_DESCENDANTS=${EXPECTED_DESCENDANTS:-7534}   # 7.535 filas del CSV menos el fondo
php_atom() { docker exec "$ATOM" sh -c "cd /atom/src && php -d memory_limit=-1 symfony $*"; }
sql() { docker exec "$DB" sh -c "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -N atom -e \"$1\" 2>/dev/null"; }

case "${1:-}" in
load)
  [[ -f $CSV ]] || { echo "falta $CSV: genera primero el CSV (migration/README.md)"; exit 2; }
  [[ $(sql "select count(*) from slug where slug like '$SLUG%'") == 0 ]] || { echo "ya existe un fondo '$SLUG': ejecuta rollback antes"; exit 3; }
  docker cp config/atom/reconcile-terms-v2-asunto.php "$ATOM":/tmp/reconcile-terms-v2-asunto.php
  php_atom tools:run /tmp/reconcile-terms-v2-asunto.php
  for code in seccion remitente destinatario asunto; do
    [[ $(sql "select count(*) from term where code='archivo-historico:$code'") == 1 ]] || { echo "falta el término archivo-historico:$code"; exit 4; }
  done
  sql "delete from keymap where source_name='independencia'"
  docker cp "$CSV" "$ATOM":/tmp/ind.csv
  php_atom csv:check-import -i /tmp/ind.csv
  php_atom csv:import --no-confirmation --source-name=independencia --error-log=/tmp/independencia-error.log /tmp/ind.csv
  php_atom search:populate
  echo "OK: $(sql "select count(*) from information_object") descripciones en la BD"
  ;;
rollback)
  found=$(sql "select count(*) from information_object o join slug s on s.object_id=o.id where s.slug='$SLUG'")
  [[ $found == 1 ]] || { echo "no hay exactamente un fondo con slug '$SLUG'"; exit 3; }
  php_atom propel:build-nested-set >/dev/null
  have=$(sql "select (select count(*) from information_object c where c.lft>o.lft and c.rgt<o.rgt) from information_object o join slug s on s.object_id=o.id where s.slug='$SLUG'")
  [[ $have == "$EXPECTED_DESCENDANTS" ]] || { echo "el fondo '$SLUG' tiene $have descendientes (se esperaban $EXPECTED_DESCENDANTS): no se borra. Define EXPECTED_DESCENDANTS si es correcto."; exit 5; }
  php_atom tools:delete-description -B "$SLUG" --no-confirmation
  sql "delete from keymap where source_name='independencia'"
  echo "OK: fondo '$SLUG' borrado"
  ;;
*) echo "uso: $0 load|rollback"; exit 2 ;;
esac
