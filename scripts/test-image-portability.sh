#!/usr/bin/env bash
# Prueba de la capa de portabilidad de la imagen AtoM (docker/atom/Dockerfile, servicio `atom_upstream` +
# `x-atom-image` en compose.yaml): en un checkout Windows con core.autocrlf=true, upstream/atom llega al build
# con CRLF y rompe en runtime cualquier script invocado por su shebang. La capa corrige SOLO la representación
# EOL dentro de la imagen final, sin tocar upstream/atom ni el resto del contenido.
#
#   scripts/test-image-portability.sh
#
# Compara la imagen final (archivo-historico/atom:2.10.2) contra la imagen upstream cruda
# (archivo-historico/atom-upstream:2.10.2, servicio build-only `atom_upstream`), así que ambas deben existir
# (`docker compose build` las construye). No arranca el runtime ni toca estado del proyecto DEV.
#
# Cross-platform por diseño: la imagen cruda puede llegar en CRLF (checkout Windows con core.autocrlf=true) o
# ya en LF (WSL/Linux, entorno de referencia: upstream/atom tiene index LF, así que un checkout Linux normal
# no introduce CRLF). El test NUNCA exige CRLF en la cruda como condición de PASS: solo la reporta como dato
# informativo y, cuando SÍ hay CRLF de partida en este host, comprueba además que la capa hizo la conversión.
set -euo pipefail

cd "$(dirname "$0")/.."
COMPOSE=(docker compose)
FINAL=archivo-historico/atom:2.10.2
RAW=archivo-historico/atom-upstream:2.10.2
fails=0

expect() { # <descripción> <esperado> <resultado>
  if [[ "$3" == "$2" ]]; then echo "PASS  $1 -> ${3:0:70}"; else echo "FAIL  $1 -> ${3:0:300} (esperado ${2:0:300})"; fails=$((fails + 1)); fi
}
expect_ne() { # <descripción> <no esperado> <resultado>
  if [[ "$3" != "$2" ]]; then echo "PASS  $1 -> ${3:0:70}"; else echo "FAIL  $1 -> ${3:0:300} (no debía ser ${2:0:300})"; fails=$((fails + 1)); fi
}

in_final() { docker run --rm --entrypoint sh "$FINAL" -c "$1"; }
in_raw() { docker run --rm --entrypoint sh "$RAW" -c "$1"; }

echo "== A. upstream/atom (source) intacto =="
expect "checkout de upstream/atom sin cambios" "" "$(git -C upstream/atom status --porcelain)"

echo "== B. entrypoint upstream: LF, no CRLF, en la imagen final (invariante cross-platform) =="
# CRLF, no CR arbitrario: CR inmediatamente antes de fin de línea (`$` ancla a fin de línea), la misma
# condición que corrige el Dockerfile (`sed "s/<CR>$//"`). Un CR en mitad de una línea (p. ej. un spinner
# `printf '\r...'` sin salto de línea) no es una línea CRLF y no debe contarse como tal.
RAW_ENTRYPOINT_STATE="$(in_raw 'grep -q "$(printf "\r")$" docker/entrypoint.sh 2>/dev/null && echo crlf || echo lf')"
echo "INFO  docker/entrypoint.sh en la imagen cruda (atom_upstream): $RAW_ENTRYPOINT_STATE (informativo, NO es condición de PASS: CRLF es lo esperable en Windows con core.autocrlf=true, LF es lo esperable en WSL/Linux)"
expect "docker/entrypoint.sh de la imagen final SIN líneas CRLF (invariante, cualquier host)" "lf" \
  "$(in_final 'grep -q "$(printf "\r")$" docker/entrypoint.sh 2>/dev/null && echo crlf || echo lf')"
expect "primera línea del entrypoint sigue siendo el shebang esperado" "#!/usr/bin/env bash" \
  "$(in_final "head -n1 docker/entrypoint.sh")"
if [[ "$RAW_ENTRYPOINT_STATE" == crlf ]]; then
  expect "con CRLF de partida en este host: la capa convirtió el contenido (final != crudo)" "convertido" \
    "$([[ "$(in_raw 'sha256sum docker/entrypoint.sh' | awk '{print $1}')" != "$(in_final 'sha256sum docker/entrypoint.sh' | awk '{print $1}')" ]] && echo convertido || echo intacto)"
else
  expect "sin CRLF de partida en este host: nada que convertir (final idéntico al crudo)" "intacto" \
    "$([[ "$(in_raw 'sha256sum docker/entrypoint.sh' | awk '{print $1}')" == "$(in_final 'sha256sum docker/entrypoint.sh' | awk '{print $1}')" ]] && echo intacto || echo convertido)"
fi

echo "== C. Alcance por contenido (shebang), no por extensión: ningún script con shebang queda en CRLF =="
SHEBANG_WITH_CRLF="$(in_final 'find . -type f | while IFS= read -r f; do
  case "$(head -c 2 "$f" 2>/dev/null)" in
    "#!") grep -q "$(printf "\r")$" "$f" 2>/dev/null && echo "$f" ;;
  esac
done
true')"
expect "sin ficheros con shebang y CRLF tras la capa" "" "$SHEBANG_WITH_CRLF"
expect "el CLI de Symfony (shebang sin extensión .sh) forma parte del universo corregido" "#!/usr/bin/env php" \
  "$(in_final "head -n1 symfony")"
expect "el CLI de Symfony, sin CRLF" "lf" \
  "$(in_final 'grep -q "$(printf "\r")$" symfony 2>/dev/null && echo crlf || echo lf')"

echo "== D. Binarios no alterados (idénticos byte a byte a la imagen upstream) =="
expect "favicon.ico idéntico" "$(in_raw 'sha256sum favicon.ico' | awk '{print $1}')" \
  "$(in_final 'sha256sum favicon.ico' | awk '{print $1}')"
expect "images/add.png idéntico" "$(in_raw 'sha256sum images/add.png' | awk '{print $1}')" \
  "$(in_final 'sha256sum images/add.png' | awk '{print $1}')"

echo "== D'. Ficheros de texto sin shebang: contenido intacto (no se toca lo que no es la superficie) =="
expect "composer.json idéntico (JSON, sin shebang)" "$(in_raw 'sha256sum composer.json' | awk '{print $1}')" \
  "$(in_final 'sha256sum composer.json' | awk '{print $1}')"

echo "== E. ENTRYPOINT/CMD compatibles con upstream; el entrypoint real arranca (sin bypass) =="
expect "mismo ENTRYPOINT que la imagen upstream" "$(docker inspect -f '{{json .Config.Entrypoint}}' "$RAW")" \
  "$(docker inspect -f '{{json .Config.Entrypoint}}' "$FINAL")"
expect "mismo CMD por defecto que la imagen upstream" "$(docker inspect -f '{{json .Config.Cmd}}' "$RAW")" \
  "$(docker inspect -f '{{json .Config.Cmd}}' "$FINAL")"
# Prueba funcional real: SIN --entrypoint (a diferencia de in_final/in_raw), para que sea el propio
# ENTRYPOINT de la imagen (docker/entrypoint.sh) el que el kernel resuelva vía su shebang, tal cual pasa en
# compose.yaml. El entrypoint upstream invoca `php .../bootstrap.php` ANTES de fpm/worker
# (upstream/atom/docker/entrypoint.sh:15) y bootstrap.php, sin las variables ATOM_* (no se pasan aquí a
# propósito), falla rápido y de forma controlada con "Environment variable ATOM_ELASTICSEARCH_HOST is not
# defined!" y exit 1: es el PRIMER getenv_or_fail del array $CONFIG (upstream/atom/docker/bootstrap.php),
# confirmado leyendo el fichero contra el commit vendorizado (02a70b8a4b23a805256abd0a14cd0f93e311a581, v2.10.2);
# el orden es estable mientras no cambie ese commit. Si el shebang siguiera roto por CRLF, el fallo sería del
# kernel resolviendo el intérprete (exec format / "No such file or directory" vía env, exit 127) y jamás se
# llegaría a ejecutar una sola línea de bash ni de PHP. Distinguir ambos casos (exit code y progreso real
# hasta ese mensaje concreto) es la prueba de que el bug original (`env: can't execute 'bash\r'`) no
# reaparece, sin necesitar BD/ES/Gearman/Memcached arriba.
ENTRYPOINT_RC=0
ENTRYPOINT_OUTPUT="$(docker run --rm "$FINAL" 2>&1)" || ENTRYPOINT_RC=$?
expect "el entrypoint real (sin override) no falla por shebang roto (exit code != 127)" "no_127" \
  "$([[ "$ENTRYPOINT_RC" -eq 127 ]] && echo "127" || echo "no_127")"
expect "el entrypoint real llegó a invocar bootstrap.php (bash y PHP se ejecutaron tras el shebang)" "1" \
  "$(grep -c "Environment variable ATOM_ELASTICSEARCH_HOST is not defined" <<<"$ENTRYPOINT_OUTPUT" 2>/dev/null || true)"

echo "== F. atom_upstream es build-only (declaración; el comportamiento en 'up' real lo prueba test-fresh-e2e.sh) =="
expect "atom_upstream declarado con replicas 0 (build-only: no arranca con up)" "1" \
  "$("${COMPOSE[@]}" config --format json | grep -A8 '"atom_upstream"' | grep -c '"replicas": 0')"

echo
if ((fails)); then echo "test-image-portability: $fails fallo(s)"; exit 1; fi
echo "test-image-portability: OK"
