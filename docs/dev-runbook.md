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

Servicios por defecto: `percona`, `elasticsearch`, `memcached`, `gearmand`, `bootstrap`, `reconcile`, `theme_build`, `atom`, `atom_worker`, `nginx`.
Orden real: infraestructura sana → `bootstrap` (termina con 0) → `reconcile` (termina con 0) y, en paralelo e independiente de la BD,
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

- `upstream/atom` (p. ej. otro commit del submódulo): imagen `atom` (compartida por `bootstrap`, `theme_build`, `atom` y `atom_worker`).
- `docker/nginx/Dockerfile` o los estáticos de la imagen `atom` (la imagen `nginx` copia de ella su `dist`): imagen `nginx`.

**No** hace falta `--build` para el uso cotidiano ni para cambios en ficheros montados por bind: el contenedor ve
siempre el fichero actual del host, sin rebuild.

- `plugins/arUnicaucaB5Plugin/` (theme): los template overrides y `images/` se ven con solo refrescar (otros cambios PHP/config del plugin: sin garantía, ver
  el theme guide); SCSS/JS requieren
  `docker compose run --rm theme_build`. Nunca rebuild de imagen. Ver [theme-development.md](theme-development.md).
- `docker/nginx/nginx.conf`: Nginx solo lee su configuración al arrancar, así que hay que hacer que la relea:
  `docker compose restart nginx`.
- `config/atom/reconcile-plugins.sh` y `config/atom/required-plugins.conf` (montados RO en `reconcile`): tampoco requieren rebuild; se
  ejercen en el siguiente `up` o con `docker compose run --rm reconcile`.
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

## Reconcile de plugins (desired state)

`reconcile` (one-shot, como `bootstrap` y `theme_build`) alinea la **única** propiedad de AtoM que el proyecto gestiona hoy:
`arUnicaucaB5Plugin` **debe estar habilitado**. Vive en `config/atom/`:

| Fichero | Papel |
| --- | --- |
| `config/atom/required-plugins.conf` | Desired state: un plugin por línea (`#` comenta). Hoy solo `arUnicaucaB5Plugin` |
| `config/atom/reconcile-plugins.sh` | El reconcile (corre dentro de la imagen AtoM; sin dependencias nuevas en el host) |
| `config/atom/tests/test-reconcile-plugins.sh` | Prueba de integración (ver [Pruebas](../README.md#pruebas)) |

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

Exit de `reconcile`: 0 éxito; 64 entrada inválida (`.conf` ausente, vacío o con una línea que no es un nombre de plugin); 65 source mínimo del
plugin inválido (nada se escribe: todo el `.conf` se valida antes de escribir); 70 no se pudo observar la lista; 71 `tools:atom-plugins add` falló;
72 postcondición no satisfecha tras añadir. Con cualquier valor distinto de 0, `atom`, `atom_worker` y `nginx` no arrancan.

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

Los fallos del `reconcile` (exits 64/65/70/71/72) se describen en [Reconcile de plugins](#reconcile-de-plugins-desired-state).

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
`downloads_data` y `theme_dist` (derivado: el siguiente `up` lo reconstruye con `theme_build`). No elimina imágenes, el checkout ni otros proyectos Docker. Después, el siguiente `up -d --wait` es una
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
| `config` de AtoM | regenerable | El entrypoint la genera desde el entorno en cada arranque |
| `dist` upstream y estáticos de AtoM | derivado | Vienen de la imagen; en DEV `theme_dist` sombrea `dist` en nginx |
| Memcached, Gearmand | efímero | Sin volumen a propósito |

## Pendiente (fuera de este runbook)

Producción, TLS, backup/restore, monitorización, CI/CD, seed de datos, migraciones, gestión de `setting` o de más plugins vía reconcile,
hot-reconcile/concurrencia y el diseño institucional final: no están decididos y no se describen aquí.
