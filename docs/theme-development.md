# Desarrollo del theme (`arUnicaucaB5Plugin`)

Guía compacta del ciclo DEV del theme institucional. Solo DEV. Entrada rápida: [README](../README.md); layout del repo:
[repository-layout](repository-layout.md); operación general: [runbook](dev-runbook.md).

El theme es un plugin de AtoM 2.10.2 **propio del proyecto** que **extiende `arDominionB5Plugin` sin modificarlo**: hoy es una
**base funcional** (identidad propia + ciclo de desarrollo), no el diseño institucional final.

## Origen de la base

Materializado desde el skeleton oficial recomendado por AtoM 2.10 para themes BS5,
[artefactual-labs/arThemeB5Plugin](https://github.com/artefactual-labs/arThemeB5Plugin), commit exacto
`32743ffc5b008f09abea71f004e139174c1e8194` (2026-03-19). **AtoM target: 2.10.2.** Cambios respecto al skeleton: nombre
(`arUnicaucaB5Plugin`), summary/versión, marcador de foundation (`scss/_foundation-marker.scss` + `<meta name="atom-theme">`),
`images/image.png` propio del skeleton y **una divergencia deliberada**: `templates/_layout_start_webpack.php` parte de
`arDominionB5Plugin` 2.10.2, no de la del skeleton. Webpack exige ese fichero en cada plugin, y el del skeleton está
desfasado (sin `googleAnalytics`, favicon configurable, atributo `media` ni la clase `show-edit-tooltips` de 2.10.2), lo que
degradaría en silencio el theme base. Al actualizar el skeleton o AtoM, revisar ese fichero contra el de Dominion.

La Configuration hereda de `arDominionB5PluginConfiguration` y conserva **sin cambios** la lógica del skeleton (antepone sus
templates a los de Dominion y sube el plugin al primer puesto). Solo se añaden overrides cuando hacen falta: copia el fichero
de `arDominionB5Plugin` o de `apps/qubit` a la misma ruta bajo el plugin (ver README del skeleton). SCSS y JS importan los de
Dominion (`../../arDominionB5Plugin/…`): no hay copias.

## Dónde vive y qué es source / derivado

| Ruta | Naturaleza |
| --- | --- |
| `plugins/arUnicaucaB5Plugin/` | **Source** del theme, versionado (`config/`, `templates/_layout_start_webpack.php`, `scss/main.scss`, `js/main.js`, `images/`, `webpack.entry.js`) |
| `plugins/arUnicaucaB5Plugin/tools/build.sh` | Wrapper de build del theme (se ejecuta dentro de Docker) |
| `plugins/arUnicaucaB5Plugin/tests/test-theme.sh` | Prueba de integración del ciclo del theme |
| `plugins/arUnicaucaB5Plugin/templates/_layout_start.php` | **Derivado** por Webpack; ignorado por Git; no se edita (edita `_layout_start_webpack.php`) |
| Volumen `archivo-historico_theme_dist` (`/atom/src/dist`) | **Derivado y reconstruible**; sin backup; no está en el repo |
| `upstream/atom/` | Nunca se modifica |

## Cómo se conecta al runtime

- `atom` y `atom_worker` montan `plugins/arUnicaucaB5Plugin` **RO** sobre `/atom/src/plugins/arUnicaucaB5Plugin` (solo ese
  directorio; nunca `/atom/src` completo). El worker lo monta por paridad del source de la aplicación.
- `theme_build` (one-shot, misma imagen que `atom`) lo monta **RW** y escribe además `theme_dist`.
- `nginx` monta `theme_dist` **RO** en `/atom/src/dist` y **solo** `plugins/arUnicaucaB5Plugin/images` (RO) como estático
  directo. `scss/`, `js/`, `templates/`, `webpack.entry.js` y el PHP no se exponen por HTTP.
- `atom`, `atom_worker` y `nginx` dependen de `theme_build` (`service_completed_successfully`): en un checkout limpio el
  runtime no consume el theme antes de que exista `_layout_start.php`. `bootstrap` (BD) **no** depende del theme build.

No hay Node/npm ni PHP en el host: Webpack y Node ya están en la imagen AtoM.

## Arrancar

```bash
docker compose up -d --wait
```

`up` ejecuta `theme_build` en cada arranque (unos 20 s): `npm run build` con `clean: true` deja `dist` reconciliado con el
source actual. No se confía en el estado previo del volumen.

## Editar templates / overrides PHP

Contrato (lo que está verificado):

- **Template overrides del theme** (p. ej. `templates/_footer.php`, o los de `modules/*/templates/`): editar → refrescar el
  navegador. Sin `symfony cc`, sin reload de FPM, sin rebuild de imagen. Hay que contar con la revalidación de OPcache:
  `atom` corre con `ATOM_DEVELOPMENT_MODE=true` (solo `atom`, solo este Compose DEV), que activa
  `opcache.validate_timestamps=On` con `opcache.revalidate_freq` = 2 s, así que un cambio puede tardar hasta ~2 s en verse.
- **Otros cambios PHP/configuración del plugin** (la clase `Configuration`, actions, cualquier código que Symfony cachee):
  están bind-mounted, pero **no se garantiza** que se apliquen sin `symfony cc` o reinicio de `atom`. Se caracterizará cuando
  aparezca un caso real; hasta entonces, ante la duda: `docker compose exec atom php symfony cc` o `docker compose restart atom`.

`ATOM_DEVELOPMENT_MODE` también expone `/qubit_dev.php`, accesible solo desde el host DEV porque el puerto web sigue
publicado únicamente en `127.0.0.1`. No cambia `NODE_ENV`.

Ejemplo de override (el que usa `test-theme.sh`, temporal): copiar `apps/qubit/templates/_footer.php` a
`plugins/arUnicaucaB5Plugin/templates/_footer.php` y editarlo. `_layout_start_webpack.php`, en cambio, es una plantilla de
build: editarla requiere `theme_build`.

## Editar SCSS / JS

Tras editar `scss/**` o `js/**`, ejecuta el build explícito y refresca:

```bash
docker compose run --rm theme_build; echo $?
```

Usa la misma imagen, el mismo bind del plugin y el mismo `theme_dist`; corrige el ownership de `_layout_start.php`; sale con
código distinto de cero si el build o la comprobación de coherencia fallan. No hay `watch` (mejora futura, no requisito).
Un cambio de SCSS/JS **no** se ve sin este build.

Detalles del wrapper: ejecuta `npm run build` upstream desde `/atom/src`; al terminar (también si falla) corrige **solo** el
ownership de `templates/_layout_start.php` con el UID:GID leído del directorio `templates/` del host (nada hardcodeado, sin
sudo, sin chmod); tras un build correcto verifica que todo bundle `/dist/...` referenciado por ese partial existe en `dist/`.

## Estáticos directos (`images/`)

Un fichero bajo `plugins/arUnicaucaB5Plugin/images/` se sirve por Nginx tal cual, con solo refrescar (bind RO del directorio;
sin build, sin recrear contenedores). URL: `/plugins/arUnicaucaB5Plugin/images/<fichero>`.

## Qué NO requiere rebuild de imagen

Nada del trabajo cotidiano del theme: template overrides (refresh), SCSS/JS (`theme_build`), images (refresh). Solo hay que
reconstruir imágenes (`up -d --build --wait`) si cambia `upstream/atom` o `docker/nginx/Dockerfile`.

## Activación del theme: TEMPORAL / MANUAL hasta WU-13

El plugin **no** se activa solo: activarlo es estado de la BD (reconcile, fuera de esta WU). Para verlo en DEV:

```bash
docker compose exec atom php symfony tools:atom-plugins add arUnicaucaB5Plugin
docker compose exec atom php symfony tools:atom-plugins list
# desactivar:
docker compose exec atom php symfony tools:atom-plugins delete arUnicaucaB5Plugin
```

La precedencia sobre `arDominionB5Plugin` viene de la Configuration del skeleton (extiende Dominion y antepone sus templates),
así que el theme custom gana **aunque Dominion siga habilitado**; deshabilitar Dominion (`tools:atom-plugins delete
arDominionB5Plugin`) también funciona (probado en aislado). Qué themes quedan habilitados es decisión del reconcile (WU-13).
Verificación: el HTML contiene `<meta name="atom-theme" content="arUnicaucaB5Plugin">`. Ni `theme_build` ni el
arranque tocan la BD. Esta activación manual desaparece cuando exista el desired state (`config/atom/`, WU-13).

## Troubleshooting mínimo

| Síntoma | Causa / acción |
| --- | --- |
| Estilos o JS 404 (`/dist/...`) o la página sin estilos | El partial referencia un bundle que no está en `dist`. `docker compose run --rm theme_build` y refresca. Si Nginx no ve `theme_dist`: `docker compose up -d --wait` |
| Cambio de SCSS/JS no visible | Falta el build (`theme_build`) o el navegador cacheó el HTML anterior (Ctrl+F5) |
| Override de template no visible | Espera ~2 s (OPcache); confirma `docker compose exec atom php -i \| grep validate_timestamps` = On. Si el cambio es de `Configuration` u otro PHP que Symfony cachee: `docker compose exec atom php symfony cc` o `docker compose restart atom` |
| `theme_build` falla | Léelo en su salida; corrige el SCSS/JS y repite. No es transaccional: un build fallido puede dejar `dist`/partial a medias hasta el siguiente build correcto |
| `_layout_start.php` es de `root` en el host | Lo corrige el propio `theme_build` (aunque el build falle); si borraste el fichero, repite el build |
| Durante un rebuild hay 404 breves de assets | `clean: true` vacía `dist` mientras Webpack emite; no hay publicación atómica (limitación conocida) |

## Windows

El entorno **validado** es WSL2 + Docker Desktop. Windows 10/11 nativo + Docker Desktop + PowerShell sigue **pendiente de un
smoke real**: no se declara validado. La foundation evita depender de Node/npm host, PHP host, UID/GID fijos o inotify/watch
para su corrección; `.gitattributes` fija LF en `*.js`, `*.scss`, `*.sh`, `*.php` (Webpack y Bash leen esos ficheros en un
contenedor Linux). No hay `.ps1` duplicados.

## Prueba

```bash
plugins/arUnicaucaB5Plugin/tests/test-theme.sh    # ~2 min; requiere el runtime arriba
```

Revierte lo que toca (ediciones, activación temporal si la hizo ella, y reconstruye el theme). No destruye estado.
