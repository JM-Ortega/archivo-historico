# Runbook DEV

Operación y diagnóstico del runtime DEV (`compose.yaml`, entorno local de desarrollo; no define producción). Entrada rápida: [README](../README.md).
Alcance: **solo DEV**. Producción, TLS, backup/restore, monitorización y CI/CD no están definidos aquí.

Todos los comandos se ejecutan desde la raíz del repo. Compose detecta `compose.yaml` solo: no hacen falta `-f` ni
`--profile` para el trabajo normal.

El proyecto Compose es `archivo-historico` (fijado por `name:` en el compose para la instancia DEV canónica): contenedores
`archivo-historico-<servicio>-1`, red `archivo-historico_default` y volúmenes `archivo-historico_<volumen>`.

## Arranque

**Runtime completo** (el arranque por defecto):

```bash
docker compose up -d --wait
scripts/web-ready.sh --wait
```

Servicios por defecto: `percona`, `elasticsearch`, `memcached`, `gearmand`, `dev_secrets`, `bootstrap`, `reconcile`, `theme_build`, `atom`, `atom_worker`, `nginx`.
Orden real: infraestructura sana y `dev_secrets` (termina con 0) → `bootstrap` (termina con 0) → `reconcile` (termina con 0) y, en paralelo e independiente de la BD,
`theme_build` (termina con 0) → `atom` y `atom_worker` → `nginx`. Si `bootstrap`, `reconcile` o `theme_build` fallan, `up` falla y
`atom`, `atom_worker` y `nginx` no arrancan (son gates). `theme_build` se ejecuta en cada `up` (~20 s); ver [theme-development.md](theme-development.md). En un checkout sin imágenes propias, ese mismo
`up -d --wait` las construye (no hace falta `docker compose build` antes).

**Profiles:** ningún servicio del runtime lleva profile. `tools` es opt-in y solo contiene `db-probe`
(`docker compose --profile tools config --services` lo añade a la lista). `docker compose run --rm db-probe` activa
el profile por sí solo al nombrar el servicio.

**Solo infraestructura** (diagnóstico; sin bootstrap, AtoM ni web): seleccionar servicios explícitamente basta.

```bash
docker compose up -d --wait percona elasticsearch memcached gearmand
```

Para volver al runtime completo, `docker compose up -d --wait`.

## Reconstruir imágenes

`up` construye las imágenes que **no existen**, pero **no reconstruye** una imagen existente aunque su origen haya
cambiado. Usa `--build` solo cuando cambió algo que entra en la imagen:

```bash
docker compose up -d --build --wait
```

- `upstream/atom` (p. ej. otro commit del submódulo): imagen `atom-upstream` (build-only, ver abajo) y, en cadena,
  imagen `atom` (compartida por `bootstrap`, `theme_build`, `atom` y `atom_worker`).
- `docker/atom/Dockerfile` (capa de portabilidad propia sobre la imagen upstream): imagen `atom`, en cadena.
- `docker/nginx/Dockerfile` o los estáticos de la imagen `atom` (la imagen `nginx` copia de ella su `dist`): imagen `nginx`.

**La imagen `atom` no es directamente el Dockerfile de `upstream/atom`.** Es una construcción en dos capas:

1. `atom_upstream`: build de `upstream/atom` sin tocar (mismo Dockerfile upstream). No forma parte del runtime
   (`deploy.replicas: 0`: `docker compose up` normal nunca la arranca ni deja un contenedor); existe solo como
   fuente de build para la imagen final.
2. `atom` (`docker/atom/Dockerfile`): `FROM` esa imagen upstream (contexto adicional `upstream: service:atom_upstream`)
   y normaliza a LF, dentro de la imagen, cualquier fichero de texto con shebang (`#!...`, detectado por contenido, no
   por extensión). Necesario porque en un checkout Windows con `core.autocrlf=true` el working tree de
   `upstream/atom` llega al build en CRLF (miles de ficheros; `upstream/atom` no lleva `.gitattributes` propio y no
   es superficie de este proyecto), lo que rompe en runtime cualquier script invocado por su shebang. `upstream/atom`
   en sí no se toca: la normalización ocurre solo dentro de la imagen derivada.

`docker compose build`/`up -d --wait` construyen ambas capas automáticamente, sin flags ni pasos manuales (la
resolución de `additional_contexts: service:atom_upstream` no requiere `--profile`). Ver
[scripts/test-image-portability.sh](../scripts/test-image-portability.sh).

**No** hace falta `--build` para el uso cotidiano ni para cambios en ficheros montados por bind: el contenedor ve
siempre el fichero actual del host, sin rebuild.

- `plugins/arUnicaucaB5Plugin/` (theme): los template overrides y `images/` se ven con solo refrescar (otros cambios PHP/config del plugin: sin garantía, ver
  el theme guide); SCSS/JS requieren
  `docker compose run --rm theme_build`. Nunca rebuild de imagen. Ver [theme-development.md](theme-development.md).
- `docker/nginx/nginx.conf`: Nginx solo lee su configuración al arrancar, así que hay que hacer que la relea:
  `docker compose restart nginx`.
- `config/atom/reconcile-plugins.sh` y `config/atom/required-plugins.conf` (montados RO en `reconcile`): tampoco requieren rebuild; se
  ejercen en el siguiente `up` o con `docker compose run --rm reconcile`.
- `config/atom/runtime-config.sh` (entrypoint del proyecto, montado RO en `bootstrap`/`reconcile`/`atom`/`atom_worker`), `config/atom/dev-secrets.sh` y
  `config/atom/reconcile*.{sh,php}`: tampoco requieren rebuild; se ejercen al recrear el servicio (ver
  [Configuración crítica](#configuración-crítica-de-atom-cultura-timezone-secreto-csrf-y-updates)).
- `scripts/*` montados (`bootstrap.sh`, `db-probe.php`, `installation-check.php`, `worker-health.sh`): tampoco requieren
  rebuild. Para ejercer un cambio, vuelve a ejecutar el servicio o comando que usa ese script (p. ej.
  `docker compose run --rm bootstrap` o `run --rm db-probe`; `worker-health.sh` lo ejecuta el healthcheck de
  `atom_worker` de forma periódica).

Aviso: los tags de imagen son fijos (ver [Segunda instancia](#segunda-instancia-aislada-y-prueba-e2e)).

## Diagnóstico

```bash
# estado y salud de todos los servicios (bootstrap aparece como Exited (0) cuando fue bien)
docker compose ps -a

# estado de la BD (db-probe): DB_COMPATIBLE | DB_FRESH | DB_UNKNOWN | DB_SCHEMA_MISMATCH | DB_UNREACHABLE
docker compose run --rm db-probe; echo $?

# plugins requeridos habilitados (reconcile; 0 = OK)
docker compose run --rm reconcile; echo $?

# instalación completa: INSTALL_COMPLETE | INSTALL_INCOMPLETE
docker compose run --rm --no-deps --entrypoint php bootstrap /project/scripts/installation-check.php

# la web responde y es AtoM (no un 200 estático)
scripts/web-ready.sh

# el worker está operativo (proceso jobs:worker + registro en Gearmand)
docker compose exec -T atom_worker bash /project/scripts/worker-health.sh; echo $?

# logs
docker compose logs bootstrap
docker compose logs --tail=100 atom
docker compose logs --tail=100 atom_worker
docker compose logs --tail=100 nginx
```

Qué mirar en `logs bootstrap`: la línea `bootstrap: probe: <ESTADO> (exit N)` es el estado inicial de la BD; en una
instalación nueva aparece `BD FRESH: ejecutando tools:install (una sola vez)` seguido de `post-probe: DB_COMPATIBLE` e
`instalación completa verificada`. Con una BD ya instalada solo verás `BD compatible; no se instala`.

## Configuración crítica de AtoM: cultura, timezone, secreto CSRF y updates

Cuatro propiedades, cada una con su dueño. Ninguna convierte `settings.yml` ni la tabla `setting` en estado autoritativo: lo autoritativo es
*defaults del proyecto + entorno + secreto*, y los ficheros runtime se **derivan** en cada arranque.

| Propiedad | Ownership | Dónde se declara | Cómo se aplica |
| --- | --- | --- | --- |
| `default_culture = es` | PROJECT-MANAGED | constante `PROJECT_DEFAULT_CULTURE` de `config/atom/runtime-config.sh` | entrypoint, ANTES de cualquier comando (incl. `tools:install`) |
| timezone (DEV: `America/Bogota`) | ENVIRONMENT-MANAGED | variable de host `ATOM_TIMEZONE` (default DEV en `compose.yaml`) → `ATOM_PHP_DATE_TIMEZONE` en los contenedores | entrypoint: `php.ini` (upstream) **y** `default_timezone` de Symfony (proyecto), de la misma variable |
| `csrf_secret` | ENVIRONMENT-MANAGED SECRET | **fichero** `ATOM_CSRF_SECRET_FILE` (`/run/atom-secrets/csrf_secret`); en DEV lo genera `dev_secrets` | entrypoint: se vuelca en el `settings.yml` efímero del contenedor |
| `check_for_updates = 0` | PROJECT-MANAGED (tabla `setting`) | `config/atom/reconcile-check-for-updates.php` | `reconcile` (pre-start), con verificación de postcondición |

**Entrypoint del proyecto** (`config/atom/runtime-config.sh`, en `bootstrap`, `reconcile`, `atom` y `atom_worker`; lo demás lo sigue haciendo el entrypoint upstream, al que
delega con `exec`): escribe exactamente tres claves (`default_culture`, `default_timezone`, `csrf_secret`) en `settings.yml` **y** en su `settings.yml.tmpl`. El `.tmpl` es la copia del
contenedor (la imagen y el submódulo no cambian): `tools:install` **borra** `settings.yml` y lo regenera desde el `.tmpl`, así que un `settings.yml` correcto no bastaría y la
instalación correría con `en`/Vancouver/`change_me`. Ambos ficheros viven en la capa escribible del contenedor y se regeneran en cada arranque; nunca se persisten. Falla (exit 64) sin
tocar nada si el timezone no es válido o falta/es inválido el fichero de secreto, y (exit 65) si el template upstream dejó de tener la forma esperada.

**Cultura.** Solo cambia el comportamiento *futuro*: la interfaz por defecto y la `source_culture` de lo que se cree sin cultura explícita (los settings del sitio y el actor del
administrador que crea `tools:install`, por ejemplo). No migra nada: los registros existentes conservan su `source_culture` y las fixtures de AtoM con cultura explícita `en` siguen en `en`.
No toca `i18n_languages`, ni reindexa. Una BD DEV anterior a esta configuración sigue funcionando con lo que ya tenía.

**Timezone.** Una sola intención. Cambiarla (`ATOM_TIMEZONE=America/Lima docker compose up -d --wait`) recrea los contenedores AtoM y afecta a ambos consumidores a la vez; si `php.ini` y
Symfony divergieran, dentro de la aplicación gana Symfony. La mayoría de las fechas de AtoM son `DATETIME` sin zona: **cambiar el timezone de un sistema con datos no reinterpreta lo ya guardado.**
Percona, Nginx y los logs siguen en UTC (aceptable; no hace falta ajustarlos).

**Secreto CSRF.**
- **DEV:** `dev_secrets` (one-shot, `config/atom/dev-secrets.sh`) genera 256 bits de `/dev/urandom` **una vez** en el volumen `atom_secrets`, con modo 0600, y no lo vuelve a tocar mientras el volumen
  exista: sobrevive a `stop`, `down`, `up` y a recrear `atom`/`atom_worker`. Los cuatro contextos AtoM montan el volumen **solo lectura**. Un fichero existente pero inválido es STOP (no se sustituye).
- **RESET DEV** (`docker compose down -v`) elimina el volumen: el siguiente `up` genera un secreto **nuevo** junto con la instalación nueva. Cambiar el secreto no migra datos ni cierra sesiones, solo invalida
  formularios ya abiertos.
- **No** está en Git, ni en la imagen, ni en el entorno de ningún contenedor (no aparece en `docker inspect`), ni en logs. Para comprobarlo sin verlo: `docker compose exec atom sha256sum /run/atom-secrets/csrf_secret | cut -c1-16` da una huella comparable.
- **Aportar un secreto externo** (otro entorno/despliegue): dejar de usar `dev_secrets`, montar un fichero propio (>= 32 caracteres de `[A-Za-z0-9_-]`, una línea) en los contenedores AtoM y apuntar `ATOM_CSRF_SECRET_FILE`
  a él. Con varias instancias web, todas deben compartir el mismo valor. Producción, gestión y rotación de secretos **no** están definidas aquí.

**`check_for_updates = 0`.** Con el default upstream (1), un administrador dispara un POST síncrono a `accesstomemory.org` (URL admin, versión, título y descripción del sitio; sin usuarios ni corpus) y, si el
servidor no responde, el render puede bloquearse. Lo aplica el segundo paso del `reconcile`, solo si la BD difiere, y **siempre invalida solo `settings:i18n:*`** con `QubitCache` (como las acciones administrativas
de AtoM): `tools:settings set` cambia la BD pero **no** invalida la caché, y las requests seguirían viendo `1` hasta ~24 h. No usa `flush_all` (destruiría las sesiones). Postcondición: BD en `0`, valor efectivo recalculado en `0`,
ninguna entrada de caché con otro valor. Solo esa fila: los demás settings no se gestionan.

Verificación de la configuración (sin imprimir el secreto):

```bash
docker compose exec -T atom sh -c 'grep -E "^ +default_(culture|timezone):" /atom/src/apps/qubit/config/settings.yml; grep -c change_me /atom/src/apps/qubit/config/settings.yml; php -r "echo ini_get(\"date.timezone\"), PHP_EOL;"'
docker compose logs reconcile | grep check_for_updates
```

Prueba de integración (proyecto aislado, no toca la DEV; ~4 min): `config/atom/tests/test-critical-config.sh`.

## Reconcile de plugins (desired state)

`reconcile` (one-shot, como `bootstrap` y `theme_build`) alinea el estado PROJECT-MANAGED de AtoM que el proyecto gestiona hoy, en dos pasos que corren en orden
(`config/atom/reconcile.sh`; el primero que falla corta): (1) `arUnicaucaB5Plugin` **debe estar habilitado** y (2) `check_for_updates = 0` (ver
[arriba](#configuración-crítica-de-atom-cultura-timezone-secreto-csrf-y-updates)). No es un reconciliador genérico. Vive en `config/atom/`:

| Fichero | Papel |
| --- | --- |
| `config/atom/required-plugins.conf` | Desired state: un plugin por línea (`#` comenta). Hoy solo `arUnicaucaB5Plugin` |
| `config/atom/reconcile.sh` | Orquesta los pasos del reconcile (plugins → `check_for_updates`) |
| `config/atom/reconcile-plugins.sh` | Paso 1: plugins (corre dentro de la imagen AtoM; sin dependencias nuevas en el host) |
| `config/atom/reconcile-check-for-updates.php` | Paso 2: `check_for_updates = 0` (vía `php symfony tools:run`) |
| `config/atom/runtime-config.sh`, `config/atom/dev-secrets.sh` | Entrypoint del proyecto y proveedor del secreto DEV (ver arriba) |
| `config/atom/tests/test-reconcile-plugins.sh`, `config/atom/tests/test-critical-config.sh` | Pruebas de integración (ver [Pruebas](../README.md#pruebas)) |

Semántica: por cada plugin requerido → valida el formato del `.conf` → valida su **source mínimo** (existe `plugins/<nombre>/config/<nombre>Configuration.class.php`,
declara la clase y es PHP válido) → observa `tools:atom-plugins list` → si **falta**, `tools:atom-plugins add` → **vuelve a observar** y exige que el
plugin figure y que la lista sea exactamente la anterior + ese plugin. Es **selectivo** (no gestiona plugins ajenos), **aditivo**, **idempotente**
(si ya está habilitado no escribe) y **no destructivo**: nunca deshabilita ni elimina nada; quitar una línea del `.conf` **no** deshabilita el plugin.
No instala, no siembra, no compila assets ni limpia cachés. La CLI de AtoM guarda incluso un plugin inexistente y sale con 0, por eso el exit 0 no se
toma como éxito.

```bash
docker compose run --rm reconcile; echo $?          # manual (diagnóstico); `up` ya lo ejecuta
docker compose logs reconcile
```

Exit de `reconcile` (plugins): 0 éxito; 64 entrada inválida (`.conf` ausente, vacío o con una línea que no es un nombre de plugin); 65 source mínimo del
plugin inválido (nada se escribe: todo el `.conf` se valida antes de escribir); 70 no se pudo observar la lista; 71 `tools:atom-plugins add` falló;
72 postcondición no satisfecha tras añadir. Paso `check_for_updates`: 73 no se pudo observar; 74 la escritura falló; 75 no se pudo invalidar la caché;
76 estado no reconciliable por override en otra cultura o postcondición no satisfecha. Con cualquier valor distinto de 0, `atom`, `atom_worker` y `nginx` no arrancan.

Límites (deliberados): supone un **único writer** durante el gate pre-start (sin locking); **no** se asume hot-reconcile sobre un AtoM ya iniciado:
si cambia el desired state, aplica con `up -d --wait` (que recrea `atom`/`atom_worker` solo si su configuración cambió) y, si hiciera falta, reiniciando
`atom`/`atom_worker`; un `run --rm reconcile` manual sobre un AtoM en marcha no garantiza que lo vea sin reiniciarlo. Los plugins ajenos (Dominion, los
`sf*Plugin`, etc.) siguen gestionándose fuera de este reconcile.

## Estados problemáticos conocidos

`bootstrap` nunca repara ni actualiza: ante cualquiera de estos estados hace STOP y `up` falla.

| Estado (exit de `bootstrap`) | Significado | Respuesta correcta |
| --- | --- | --- |
| `DB_UNKNOWN` (20) | La BD no está vacía y no se reconoce con seguridad como AtoM | No se instala encima. Averigua qué contiene (`db-probe` da el detalle por stderr). Si es un volumen DEV desechable: [RESET DEV](#reset-dev-destructivo) |
| `DB_SCHEMA_MISMATCH` (21) | Es AtoM, pero con un schema distinto del esperado por v2.10.2 | No hay migración automática. Usa la versión de AtoM que corresponde a esa BD o, si los datos son desechables, [RESET DEV](#reset-dev-destructivo) |
| `DB_UNREACHABLE` (30) | No se conectó a la BD tras los reintentos (30 × 2 s) | `ps -a` y `logs percona`. Recuerda que Percona solo aplica `MYSQL_*` al **inicializar** el volumen: cambiar la contraseña en el entorno después no cambia la de un volumen ya creado |
| `DB_COMPATIBLE` + `INSTALL_INCOMPLETE` (43) | Schema correcto pero sin administrador: un `tools:install` interrumpido | STOP: **no se reinstala ni se repara automáticamente.** Si es una instalación DEV desechable: [RESET DEV](#reset-dev-destructivo). Si hay datos que conservar: no hagas RESET; investiga y recupera de forma explícita (p. ej. inspecciona la BD y restaura el administrador o la instalación a mano) |

Los fallos del `reconcile` (exits 64/65/70/71/72 y 73-76) se describen en [Reconcile de plugins](#reconcile-de-plugins-desired-state).

Otros exits de `bootstrap`: 40 `tools:install` falló; 41 el probe posterior no dio `DB_COMPATIBLE`; 42 Elasticsearch o
Memcached no disponibles (solo al instalar); 64/70 configuración inválida o error inesperado. Empieza siempre por
`logs bootstrap`.

## Parar y rearrancar sin perder datos

```bash
docker compose stop            # conserva contenedores y volúmenes
docker compose down            # elimina contenedores y red; conserva volúmenes
docker compose up -d --wait    # rearranque (pasa por el bootstrap)
```

Ninguno de los dos borra datos. Lo que borra datos es `-v` (ver RESET). Tras rearrancar, `bootstrap` ve `DB_COMPATIBLE` +
`INSTALL_COMPLETE` y no reinstala.

## RESET DEV (destructivo)

> **DESTRUCTIVO. SOLO DEV.** Borra la base de datos, los `uploads`, los `downloads` y los índices de Elasticsearch **del
> proyecto Docker seleccionado**. No hay vuelta atrás. No es un comando cotidiano.

```bash
docker compose down -v
```

Antes de ejecutarlo, comprueba **qué proyecto** vas a borrar (sin `-p`/`COMPOSE_PROJECT_NAME` es `archivo-historico`, la
instancia DEV normal):

```bash
docker compose config --format json | grep -m1 '"name"'
```

Elimina exactamente: contenedores del proyecto, su red y `percona_data`, `elasticsearch_data`, `uploads_data`,
`downloads_data`, `atom_secrets` (el secreto CSRF local: el siguiente `up` genera **uno nuevo**) y `theme_dist` (derivado: el siguiente `up` lo reconstruye con `theme_build`). No elimina imágenes, el checkout ni otros proyectos Docker. Después, el siguiente `up -d --wait` es una
instalación completamente nueva (`DB_FRESH` → `tools:install` una vez → READY). RESET no es una reparación parcial: es la
destrucción explícita de todo el estado.

Este flujo (incluido el `down -v` real y la segunda instalación) está validado por `scripts/test-fresh-e2e.sh`, siempre
sobre un proyecto E2E aislado.

Nunca uses `docker system prune` ni `docker volume prune` como sustituto.

## Segunda instancia aislada y prueba E2E

`name: archivo-historico` está fijo en el compose (nombres DEV estables y predecibles para la instancia canónica), así que
**cualquier otro checkout comparte proyecto Docker con la DEV** salvo que lo sobrescribas con `-p`; `-p` se documenta solo
para instancias paralelas, no para el uso normal. La precedencia real (verificada) es `-p` > `COMPOSE_PROJECT_NAME` > `name:`. Para una
instancia paralela hacen falta, como mínimo, un proyecto y un puerto distintos:

```bash
export ATOM_WEB_PORT=18080
docker compose -p archivo-historico-otra up -d --wait
```

Aviso: los tags de imagen (`archivo-historico/atom:2.10.2`, `archivo-historico/nginx:2.10.2`) son fijos y no dependen del
proyecto. Un build desde otro checkout (`up` en un checkout sin imágenes, o `--build`) con `-p` distinto **reasigna esos tags** a las imágenes nuevas (los contenedores DEV en marcha
siguen con la imagen anterior, pero un futuro `up`/recreación usará la nueva). `scripts/test-fresh-e2e.sh` lo evita
renombrando las imágenes con un override propio.

```bash
scripts/test-fresh-e2e.sh          # tarda varios minutos; E2E_KEEP=1 conserva checkout y proyecto para depurar
```

Hace un `git clone` del commit actual + `git submodule update --init --recursive` en un directorio temporal, arranca con
un único `docker compose up -d --wait` (sin `build` previo) un proyecto `archivo-historico-e2e-<id>` en un puerto loopback
libre —con `-f compose.yaml -f <override de imágenes> -p <proyecto>` para no tocar los tags de la DEV—, verifica el fresh install, hace el RESET y verifica un
segundo fresh install. Comprueba antes del RESET que ningún recurso E2E coincide con los de la DEV y al final que la DEV
queda idéntica. Limpia solo lo suyo. Requiere red (submódulo) y unos minutos.

## Nginx

`docker/nginx/nginx.conf` se monta **solo lectura** en el contenedor: editarlo en el host no se aplica solo. Tras editarlo:

```bash
docker compose restart nginx
```

Los estáticos upstream (`dist`, `images`, `css`, `js`) van dentro de la imagen `nginx`; en DEV `theme_dist` (RO) sombrea `dist`
y `plugins/arUnicaucaB5Plugin/images` (RO) se monta como estático directo del theme. Cambiar el Dockerfile o `upstream/atom`
requiere `docker compose up -d --build --wait` (reconstruye y recrea `nginx`). Un 200 de Nginx no implica que AtoM funcione: usa `scripts/web-ready.sh`.

## Worker (`atom_worker`)

- **Salud:** el healthcheck exige el proceso `php symfony jobs:worker` **y** su conexión registrada en Gearmand con
  funciones (`<md5>-<ability>`). Comprobación manual: ver Diagnóstico.
- **Reinicio:** `restart: on-failure:5`. `jobs:worker` sale con error si pierde la BD; Docker lo reinicia hasta 5 veces
  contra la MISMA BD (cada arranque espera ~10 s). Gearmand, en cambio, se reconecta solo.
- **Fallo prolongado de la BD:** agotados los 5 reintentos el worker queda `unhealthy`/`Exited`. Recupéralo con
  `up -d --wait atom_worker`.
- **Restore, reemplazo o reset de la BD:** un reinicio automático **no** pasa por el bootstrap. Tras cualquier cambio de
  BD, vuelve por el ciclo Compose (`up -d --wait`, que repasa el gate de `bootstrap`; si hace falta forzar la recreación,
  `rm -sf atom_worker` y luego `up -d --wait`). No lo dejes corriendo contra una BD distinta de la que validó el bootstrap.

## Persistencia

| Volumen / recurso | Naturaleza | Nota |
| --- | --- | --- |
| `percona_data` | **autoritativo** | Base de datos. Sin backup automatizado en DEV |
| `uploads_data` | **autoritativo** | Objetos digitales; no reconstruibles |
| `downloads_data` | conservadoramente persistente | Mezcla de reconstruible (informes, EAD/XML) y no reconstruible; se conserva |
| `elasticsearch_data` | derivado / conveniencia | Reconstruible desde la BD; no forma parte del conjunto mínimo de recuperación |
| `theme_dist` | derivado / reconstruible | Bundles del theme (`/atom/src/dist` en nginx, RO). Lo escribe solo `theme_build`; **sin backup** |
| `atom_secrets` | secreto de entorno (DEV) | Secreto CSRF local. Sin backup: no es dato; `down -v` lo regenera. Nunca en Git ni en la imagen |
| `config` de AtoM (incl. `settings.yml`) | regenerable | Los entrypoints la generan desde defaults + entorno + secreto en cada arranque; no se persiste |
| `dist` upstream y estáticos de AtoM | derivado | Vienen de la imagen; en DEV `theme_dist` sombrea `dist` en nginx |
| Memcached, Gearmand | efímero | Sin volumen a propósito |

## Pendiente (fuera de este runbook)

Producción, gestión/rotación de secretos de producción, TLS, backup/restore, monitorización, CI/CD, seed de datos, migraciones, gestión de otros `setting` o de más plugins vía reconcile,
hot-reconcile/concurrencia y el diseño institucional final: no están decididos y no se describen aquí.
