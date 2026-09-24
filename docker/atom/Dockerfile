# syntax=docker/dockerfile:1
#
# Capa de portabilidad propia del proyecto sobre la imagen AtoM upstream.
#
# `upstream` llega como contexto adicional: la imagen ya construida del servicio `atom_upstream`
# (`additional_contexts: upstream: service:atom_upstream` en compose.yaml), que a su vez es el
# Dockerfile de `upstream/atom` sin tocar. upstream/atom sigue siendo la fuente read-only del
# proyecto; esta capa no reescribe ni sustituye su build, solo corrige la REPRESENTACIÓN dentro de
# la imagen derivada.
#
# Motivo: en un checkout Windows con `core.autocrlf=true`, el working tree de `upstream/atom`
# (miles de ficheros, index LF / working tree CRLF) llega al build con CRLF. Eso rompe en runtime
# cualquier script invocado por su shebang (`env: can't execute 'bash\r': No such file or
# directory`), no solo `docker/entrypoint.sh`. `upstream/atom` no lleva `.gitattributes` propio que
# fuerce LF, y no es superficie de este proyecto para añadírselo.
#
# Alcance de la normalización: TODO fichero de texto cuyo contenido empiece por `#!` (shebang),
# detectado por contenido y no por extensión (AtoM incluye ejecutables sin `.sh`, p. ej. el CLI de
# Symfony). Los binarios y el resto del árbol (código PHP sin shebang, assets, vendor/node_modules
# ya compilados, etc.) quedan bit a bit idénticos a los de la imagen upstream.
FROM upstream

RUN <<'NORMALIZE_SHEBANG_EOL'
set -e
find /atom/src -type f | while IFS= read -r f; do
  case "$(head -c 2 "$f" 2>/dev/null)" in
    '#!') sed -i "s/$(printf '\r')\$//" "$f" ;;
  esac
done
NORMALIZE_SHEBANG_EOL
