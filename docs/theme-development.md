# Desarrollo del theme (`arUnicaucaB5Plugin`)

Contrato de trabajo para modificar la interfaz de AtoM mediante el theme institucional: cómo decidir qué intervención
corresponde a un requerimiento, hasta dónde se puede llegar dentro del theme, cuándo hay que detenerse y escalar, y cómo se
edita, compila y verifica en DEV. Solo DEV. Entrada rápida: [README](../README.md); layout del repo:
[repository-layout](repository-layout.md); operación general: [runbook](dev-runbook.md).

El theme es un plugin de AtoM 2.10.2 **propio del proyecto** que **extiende `arDominionB5Plugin` sin modificarlo**: no es un
frontend independiente. Hoy es una **base funcional** (identidad propia + ciclo de desarrollo), no el diseño institucional
final.

## Principios y contratos que guían el cambio

- `arUnicaucaB5Plugin` **extiende** `arDominionB5Plugin`. Cada override es una desviación respecto a Dominion que hay que
  mantener: se añade solo cuando hace falta.
- `upstream/atom` se **inspecciona** como referencia y **no se modifica**.
- Cada fuente que orienta el cambio manda en lo suyo:
  - **Archivo Histórico** → la necesidad funcional (qué necesita quien usa el Archivo).
  - **AtoM** → las capacidades y el comportamiento que ya existen.
  - **Sistema de Diseño TIC/UniCauca** → la presentación institucional y la UX aplicable.
  - **Arquitectura del Proyecto I** → los límites de extensión y la mantenibilidad.
  - **Referencias externas** → inspiración de patrones de UX, no especificación.
- La intervención se decide por el **contrato que cambia**, no por cómo se ve la pantalla (ver
  [Clasificar el cambio](#clasificar-el-cambio)).

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
templates a los de Dominion y sube el plugin al primer puesto). SCSS y JS importan los de Dominion
(`../../arDominionB5Plugin/…`): no hay copias. Cuándo y cómo añadir un override: ver
[Editar templates / overrides PHP](#editar-templates--overrides-php).

## Dónde vive y qué es source / derivado

| Ruta | Naturaleza |
| --- | --- |
| `plugins/arUnicaucaB5Plugin/` | **Source** del theme, versionado (`config/`, `templates/_layout_start_webpack.php`, `scss/main.scss`, `js/main.js`, `images/`, `webpack.entry.js`) |
| `plugins/arUnicaucaB5Plugin/tools/build.sh` | Wrapper de build del theme (se ejecuta dentro de Docker) |
| `plugins/arUnicaucaB5Plugin/tools/watch.sh` | Wrapper del watch DEV opt-in del theme (se ejecuta dentro de Docker) |
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
source actual. No se confía en el estado previo del volumen. El theme queda **habilitado** por el `reconcile` del mismo `up` (ver
[Activación del theme](#activación-del-theme-reconcile)).

## Antes de tocar una pantalla

Inspecciona primero; el código de `upstream/atom` se **lee**, no se edita.

1. **Reproduce la pantalla en DEV** con el theme activo y observa el HTML real. El `<body>` lleva el módulo y la acción como
   clases (`<módulo> <acción>`), útiles para acotar estilos y para localizar el template.
2. **Localiza qué la renderiza** en `upstream/atom`: el template del módulo (en `apps/qubit/modules/*` o en el plugin de
   estándares que corresponda), sus partials y su layout. Comprobado en 2.10.2: la portada se decora con `layout_2col` de la
   aplicación, no con el `layout.php` del theme; y `arDominionB5Plugin` aporta config, JS, SCSS, images (y stubs i18n vacíos) y solo
   dos templates (`layout.php` y `_layout_start_webpack.php`), así que la mayor parte de las vistas no viene de Dominion.
3. **Comprueba si ya hay configuración soportada** que resuelva el requerimiento (p. ej. el favicon configurable que ya
   contempla `_layout_start`), antes de tocar código.
4. **Identifica el contrato de servidor** de la vista: qué datos recibe, qué formularios/URLs/parámetros usa y qué acciones
   dispara. Es lo que un cambio de presentación debe conservar.
5. **Contrasta con la necesidad** (Archivo Histórico) y con el Sistema de Diseño aplicable; solo entonces clasifica.

## Clasificar el cambio

La clase se decide por el **contrato que cambia**, no por cómo se ve en pantalla. Un control que "parece" un filtro visual pero
altera qué se consulta no es Clase 1 ni 2.

- Si un cambio cumple realmente varias clases, se clasifica por la más alta cuyos criterios cumple de verdad.
- Si la duda existe porque aún no se sabe si el cambio toca un contrato funcional, se inspecciona antes de clasificar.
- Si persiste una incertidumbre material, se escala.

| Clase | Qué cambia | Superficie habitual | Ejemplos |
| --- | --- | --- | --- |
| **1 · Visual** | Solo apariencia | SCSS / assets | color, tipografía, spacing, responsive |
| **2 · Estructural** | Organización/markup, con los mismos datos y acciones | template/partial + SCSS | cards, sidebar, reordenar bloques |
| **3 · Interacción UI** | Cómo se interactúa con capacidades existentes | template + JS + SCSS | chips, accordions, tabs, show/hide |
| **4 · Functional UX** | Compone o expone de otra forma capacidades que AtoM **ya posee**; no cambia persistencia, modelo, autorización, semántica archivística ni search semantics | UI, con una adaptación pequeña directamente ligada a la UX si hace falta | agrupar en una pantalla acciones que ya existen por separado |
| **5 · Feature de aplicación** | Introduce o modifica una capacidad real | requiere **WU funcional propia** | nueva query, endpoint, ranking, indexación, persistencia, permisos, workflow |

Las clases 1–4 caben dentro del theme siempre que respeten sus límites. Si una Clase 4 obliga a cambiar cualquiera de los
contratos que la definen, **deja de ser Clase 4**: es Clase 5.

## Escalera de intervención

```text
configuración soportada
        ↓
SCSS / assets
        ↓
JS de UI
        ↓
partial / template override
        ↓
PHP component/action
        ↓
feature propia (plugin / extensión)
        ↓
core AtoM → STOP / decisión arquitectónica
```

No es una secuencia mecánica: se usa **la capa apropiada**, y entre las que resuelven **por completo** la necesidad se prefiere
la menos invasiva.

Antes de copiar una pieza upstream:

- comprobar si basta configuración soportada;
- comprobar si basta SCSS/assets;
- comprobar si basta JS de UI;
- preferir un partial pequeño antes que una vista completa.

Por encima de templates/assets/SCSS/JS **no se puede asumir** que PHP, configuración, formularios o routing se sobrescriban por
simple coincidencia de ruta: hay que inspeccionar el mecanismo concreto de AtoM/Symfony antes de implementar.

### Reutilizable vs específico de pantalla

- Un patrón es **reutilizable** cuando de verdad representa comportamiento o estilo común.
- Es **específico de pantalla** cuando es composición propia de esa pantalla.
- No se sobrediseñan componentes por anticipado; cuando un patrón empieza a repetirse, se evalúa extraerlo.
- Similitud visual no implica misma semántica: dos bloques que se ven igual pueden no ser el mismo patrón.

Este documento no fija (ni obliga a crear) una estructura de directorios SCSS/JS más allá de la actual.

## Editar templates / overrides PHP

**Copiar una pieza upstream es el último recurso de presentación, no el primero:** antes de crear el override, comprobar si el
cambio puede resolverse mediante configuración, SCSS/JS o un partial más pequeño (lista de comprobación en
[Escalera de intervención](#escalera-de-intervención)).

Cuando el override hace falta, se parte de la pieza de `arDominionB5Plugin` o de `apps/qubit` según corresponda y se copia a la
misma ruta bajo el plugin (convención del skeleton; su README lo ilustra con `apps/qubit/templates/_footer.php` →
`plugins/arUnicaucaB5Plugin/templates/_footer.php`).

Contrato de recarga (lo que está verificado):

- **Partial global del theme en `templates/`** (verificado con `templates/_footer.php`): editar → refrescar el navegador. Sin
  `symfony cc`, sin reload de FPM, sin rebuild de imagen. Hay que contar con la revalidación de OPcache: `atom` corre con
  `ATOM_DEVELOPMENT_MODE=true` (solo `atom`, solo este Compose DEV), que activa `opcache.validate_timestamps=On` con
  `opcache.revalidate_freq` = 2 s, así que un cambio puede tardar hasta ~2 s en verse. Los overrides bajo
  `modules/<módulo>/templates/` siguen la convención de rutas del skeleton, pero **las pruebas actuales no los caracterizan**:
  compruébalo en la primera WU que use uno.
- **Otros cambios PHP/configuración del plugin** (la clase `Configuration`, actions, cualquier código que Symfony cachee):
  están bind-mounted, pero **no se garantiza** que se apliquen sin `symfony cc` o reinicio de `atom`. Se caracterizará cuando
  aparezca un caso real; hasta entonces, ante la duda: `docker compose exec atom php symfony cc` o `docker compose restart atom`.

Sobre las actions: el skeleton documenta copiar actions y templates bajo `modules/<módulo>/…` con la misma ruta, y su
Configuration sube el plugin al primer puesto con esa intención; en este proyecto **solo** está caracterizado el caso del
partial global. Tratar una action como override "por ruta" exige inspeccionar antes el mecanismo concreto.

`ATOM_DEVELOPMENT_MODE` también expone `/qubit_dev.php`, accesible solo desde el host DEV porque el puerto web sigue
publicado únicamente en `127.0.0.1`. No cambia `NODE_ENV`.

`_layout_start_webpack.php` es una plantilla de build, no un override de recarga inmediata: editarla requiere `theme_build`.

## Editar SCSS / JS

Hay dos caminos, complementarios y no intercambiables:

- **`theme_build`** — one-shot, determinista. Es el camino que usan startup, pruebas, CI y recuperación.
- **`theme_watch`** — interactivo, persistente, **opt-in**, exclusivamente DEV/DX. No sustituye a `theme_build` ni
  cambia su contrato; es una herramienta de comodidad para sesiones de edición.

### `theme_build` (one-shot, determinista)

Tras editar `scss/**` o `js/**`, ejecuta el build explícito y refresca:

```bash
docker compose run --rm theme_build; echo $?
```

Usa la misma imagen, el mismo bind del plugin y el mismo `theme_dist`; corrige el ownership de `_layout_start.php`; sale con
código distinto de cero si el build o la comprobación de coherencia fallan. Un cambio de SCSS/JS **no** se ve sin este build
(salvo que tengas `theme_watch` activo, ver debajo).

Detalles del wrapper: ejecuta `npm run build` upstream desde `/atom/src`; al terminar (también si falla) corrige **solo** el
ownership de `templates/_layout_start.php` con el UID:GID leído del directorio `templates/` del host (nada hardcodeado, sin
sudo, sin chmod); tras un build correcto verifica que todo bundle `/dist/...` referenciado por ese partial existe en `dist/`.

### `theme_watch` (interactivo, opt-in, solo DEV)

Para no repetir `theme_build` a mano en cada edición durante una sesión de trabajo:

```bash
docker compose run --rm theme_watch
```

- Es **opt-in**: `docker compose up -d --wait` no lo arranca ni depende de él (profile `tools`, igual que `db-probe`).
- Observa `scss/**` y `js/**` de **`arUnicaucaB5Plugin`** (el único plugin que `theme_watch` monta RW; es la
  superficie soportada y probada) mediante `webpack watch` (invocado directamente, sin pasar por `npm run watch`,
  para un manejo de señales fiable al detener la sesión; sin polling de filesystem) y recompila automáticamente al
  detectar un cambio. En el entorno de referencia, un rebuild observado tarda del orden de **~15–20 s**; no es un
  tiempo contractual exacto.
- **No hay HMR**: tras cada rebuild, refresca el navegador a mano para ver el resultado.
- Mismo boundary de escritura que `theme_build` (plugin RW + `theme_dist` RW), misma imagen; sin puertos; no depende
  del runtime AtoM ni de la BD.
- Ownership de `templates/_layout_start.php`: a diferencia de `theme_build` (corrección al salir, one-shot), Webpack
  puede eliminar y recrear ese fichero en **cada** rebuild mientras la sesión está activa. `theme_watch` mantiene el
  ownership correcto de forma continua durante toda la sesión (un companion ligero, sin UID/GID fijos, sin sudo, sin
  chmod) y aplica una corrección final al terminar.
- **Foreground e interactivo**: `Ctrl+C` detiene watch y companion sin dejar procesos ni contenedores huérfanos, y sin
  afectar a `atom`/`atom_worker`/`nginx`. Puede volver a arrancarse sin RESET ni reinstalación.
- No observa PHP, configuración ni templates (`_layout_start_webpack.php` incluido): eso sigue el contrato ya
  caracterizado en [Editar templates / overrides PHP](#editar-templates--overrides-php).
- Validado en **WSL2 + Docker Desktop** (el mismo entorno de referencia del resto de esta guía). Windows nativo
  todavía no está validado para este flujo.

## Estáticos directos (`images/`)

Un fichero bajo `plugins/arUnicaucaB5Plugin/images/` se sirve por Nginx tal cual, con solo refrescar (bind RO del directorio;
sin build, sin recrear contenedores). URL: `/plugins/arUnicaucaB5Plugin/images/<fichero>`.

## Qué NO requiere rebuild de imagen

Nada del trabajo cotidiano del theme: template overrides (refresh), SCSS/JS (`theme_build`), images (refresh). Solo hay que
reconstruir imágenes (`up -d --build --wait`) si cambia `upstream/atom`, `docker/atom/Dockerfile` (capa de
portabilidad) o `docker/nginx/Dockerfile`.

## Gaps de AtoM y escalación

### Antes de declarar un gap

Comprueba la UI, la configuración, el source y el mecanismo soportado pertinentes (ver
[Antes de tocar una pantalla](#antes-de-tocar-una-pantalla)). La ausencia de una capacidad en la UI no constituye por sí sola
un gap. Antes de declararlo se comprueba, según el caso, la configuración, el source, la documentación o el mecanismo soportado
pertinente de AtoM.

### Decisión

```text
¿AtoM lo permite por configuración?
  sí → usarlo.
  no ↓
¿la capacidad existe pero necesita otra UX?
  sí → Clases 1–4, en el theme.
  no ↓
¿puede componerse con capacidades existentes?
  sí → Clase 4, en el theme.
  no ↓
¿puede añadirse limpiamente mediante plugin/extensión?
  sí → Clase 5: WU funcional propia.
  no ↓
¿hay otro mecanismo soportado adecuado?
  sí → evaluarlo (su propia WU/decisión).
  no ↓
STOP → decisión de arquitectura/alcance
```

- **No todo gap debe implementarse.** Puede diferirse si su valor no justifica la complejidad o el riesgo.
- Descubrir un gap durante una WU de UI **no amplía automáticamente su scope**: se documenta y se decide aparte.
- Modificar el core de AtoM (`upstream/atom`) **no es una vía ordinaria**.

### Cuándo se detiene la implementación

Detén el trabajo, documenta el hallazgo y escala (no continúes "hasta ver si cabe") si aparece, o puede aparecer:

- nueva persistencia o modelo de datos;
- nueva semántica archivística;
- nueva search semantics, ranking o indexación;
- permisos o autorización;
- workflow o job;
- import/export;
- API o action con capacidad funcional significativa;
- infraestructura;
- modificación de `upstream/atom`.

## Verificación por clase

**No se acepta un cambio únicamente porque se ve bien.** La verificación es proporcional al cambio: no se ejecuta el E2E
completo por defecto para cada cambio local.

| Clase | Verificación mínima |
| --- | --- |
| **Visual** | build y render; responsive; accesibilidad visual aplicable |
| **Estructural** | contenido y acciones conservados; estados relevantes; navegación |
| **Interacción UI** | estado inicial y final; eventos; teclado y foco; submit/navegación real |
| **Functional UX** | la UI, y demostrar que sigue usando correctamente el contrato existente de AtoM |
| **Feature de aplicación** | tests específicos del nuevo contrato; casos negativos; regresión de las capas afectadas |

Prueba de la foundation del theme (ciclo de build, mounts, Nginx, hot reload de partials):

```bash
plugins/arUnicaucaB5Plugin/tests/test-theme.sh    # ~2 min; requiere el runtime arriba
```

Revierte lo que toca (ediciones, activación temporal si la hizo ella, y reconstruye el theme). No destruye estado. Sella la
foundation actual: entre otras cosas exige que `scss/` contenga exactamente `main.scss` y `_foundation-marker.scss`, y usa
`templates/_footer.php` como override temporal (se detiene si ya existe uno durable). La WU que añada el primer parcial SCSS o
el primer override de ese fichero debe **adaptar esas aserciones de forma consciente**, no esquivarlas.

El E2E completo (`scripts/test-fresh-e2e.sh`) no se ejecuta por defecto para cada cambio local: cada WU decide según su alcance
y riesgo. Compose y el arranque son casos claros que lo justifican, pero también puede justificarlo un flujo crítico transversal.

## Mini-ficha para cambios no triviales

Puede vivir en la WU, el issue o el PR; **no implica crear otro documento**.

```text
SCREEN:
NEED:
CURRENT:
ATOM CAPABILITY:
DESIRED:
CLASS:
SERVER CONTRACT:
PERSISTENCE:
SEARCH / DOMAIN SEMANTICS:
TOUCH:
ESCALATION:
```

## Activación del theme (reconcile)

`arUnicaucaB5Plugin` está habilitado por el servicio `reconcile` (`docker compose up -d --wait` lo ejecuta antes de `atom`/`atom_worker`), a partir del
desired state versionado `config/atom/required-plugins.conf`. La presencia del plugin en el filesystem no lo habilita: la activación es estado de la BD
y pertenece al reconcile. Detalle, semántica y exits: [runbook](dev-runbook.md#reconcile-de-plugins-desired-state). `theme_build` no toca la BD.

```bash
docker compose run --rm reconcile          # aplicar/verificar a mano
docker compose exec atom php symfony tools:atom-plugins list
```

Deshabilitar el theme a mano (`tools:atom-plugins delete arUnicaucaB5Plugin`) es un experimento local: el siguiente `up` (o `run reconcile`) lo vuelve a
habilitar mientras siga en el `.conf`. El reconcile solo **añade**; nunca deshabilita ni gestiona otros plugins.

La precedencia sobre `arDominionB5Plugin` viene de la Configuration del skeleton (extiende Dominion y antepone sus templates),
así que el theme custom gana **aunque Dominion siga habilitado** (Dominion no es propiedad del reconcile: sigue como esté en la BD; deshabilitarlo
también funciona, probado en aislado). Verificación: el HTML contiene `<meta name="atom-theme" content="arUnicaucaB5Plugin">`.

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
