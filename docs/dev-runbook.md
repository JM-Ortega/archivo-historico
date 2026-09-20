# Runbook DEV

Operación y diagnóstico del runtime DEV (`compose.dev.yaml`). Entrada rápida: [README](../README.md).
Alcance: **solo DEV**. Producción, TLS, backup/restore, monitorización y CI/CD no están definidos aquí.

Todos los comandos se ejecutan desde la raíz del repo. Atajo usado abajo:

```bash
docker compose -f compose.dev.yaml --profile runtime <comando>
```

El proyecto Compose es `archivo-historico` (fijado por `name:` en el compose): contenedores `archivo-historico-<servicio>-1`,
red `archivo-historico_default` y volúmenes `archivo-historico_<volumen>`.

## Arranque

**Runtime completo** (bootstrap + atom + atom_worker + nginx sobre la infraestructura):

```bash
docker compose -f compose.dev.yaml --profile runtime build      # primera vez o tras cambiar upstream/atom o docker/nginx
docker compose -f compose.dev.yaml --profile runtime up -d --wait
scripts/web-ready.sh --wait
```

Orden real: infraestructura sana → `bootstrap` (termina con 0) → `atom` y `atom_worker` → `nginx`. Si `bootstrap` falla,
`up` falla y `atom`, `atom_worker` y `nginx` no arrancan (es el gate).

**Solo infraestructura** (Percona, Elasticsearch, Memcached, Gearmand; sin AtoM ni web):

```bash
docker compose -f compose.dev.yaml up -d --wait
```

Para pasar de infra-only a runtime basta ejecutar el `up` del runtime completo.

## Diagnóstico

```bash
# estado y salud de todos los servicios (bootstrap aparece como Exited (0) cuando fue bien)
docker compose -f compose.dev.yaml --profile runtime ps -a

# estado de la BD (db-probe): DB_COMPATIBLE | DB_FRESH | DB_UNKNOWN | DB_SCHEMA_MISMATCH | DB_UNREACHABLE
docker compose -f compose.dev.yaml run --rm db-probe; echo $?

# instalación completa: INSTALL_COMPLETE | INSTALL_INCOMPLETE
docker compose -f compose.dev.yaml --profile runtime run --rm --no-deps --entrypoint php bootstrap /project/scripts/installation-check.php

# la web responde y es AtoM (no un 200 estático)
scripts/web-ready.sh

# el worker está operativo (proceso jobs:worker + registro en Gearmand)
docker compose -f compose.dev.yaml --profile runtime exec -T atom_worker bash /project/scripts/worker-health.sh; echo $?

# logs
docker compose -f compose.dev.yaml --profile runtime logs bootstrap
docker compose -f compose.dev.yaml --profile runtime logs --tail=100 atom
docker compose -f compose.dev.yaml --profile runtime logs --tail=100 atom_worker
docker compose -f compose.dev.yaml --profile runtime logs --tail=100 nginx
```

Qué mirar en `logs bootstrap`: la línea `bootstrap: probe: <ESTADO> (exit N)` es el estado inicial de la BD; en una
instalación nueva aparece `BD FRESH: ejecutando tools:install (una sola vez)` seguido de `post-probe: DB_COMPATIBLE` e
`instalación completa verificada`. Con una BD ya instalada solo verás `BD compatible; no se instala`.

## Estados problemáticos conocidos

`bootstrap` nunca repara ni actualiza: ante cualquiera de estos estados hace STOP y `up` falla.

| Estado (exit de `bootstrap`) | Significado | Respuesta correcta |
| --- | --- | --- |
| `DB_UNKNOWN` (20) | La BD no está vacía y no se reconoce con seguridad como AtoM | No se instala encima. Averigua qué contiene (`db-probe` da el detalle por stderr). Si es un volumen DEV desechable: [RESET DEV](#reset-dev-destructivo) |
| `DB_SCHEMA_MISMATCH` (21) | Es AtoM, pero con un schema distinto del esperado por v2.10.2 | No hay migración automática. Usa la versión de AtoM que corresponde a esa BD o, si los datos son desechables, [RESET DEV](#reset-dev-destructivo) |
| `DB_UNREACHABLE` (30) | No se conectó a la BD tras los reintentos (30 × 2 s) | `ps -a` y `logs percona`. Recuerda que Percona solo aplica `MYSQL_*` al **inicializar** el volumen: cambiar la contraseña en el entorno después no cambia la de un volumen ya creado |
| `DB_COMPATIBLE` + `INSTALL_INCOMPLETE` (43) | Schema correcto pero sin administrador: un `tools:install` interrumpido | STOP: **no se reinstala ni se repara automáticamente.** Si es una instalación DEV desechable: [RESET DEV](#reset-dev-destructivo). Si hay datos que conservar: no hagas RESET; investiga y recupera de forma explícita (p. ej. inspecciona la BD y restaura el administrador o la instalación a mano) |

Otros exits de `bootstrap`: 40 `tools:install` falló; 41 el probe posterior no dio `DB_COMPATIBLE`; 42 Elasticsearch o
Memcached no disponibles (solo al instalar); 64/70 configuración inválida o error inesperado. Empieza siempre por
`logs bootstrap`.

## Parar y rearrancar sin perder datos

```bash
docker compose -f compose.dev.yaml --profile runtime stop            # conserva contenedores y volúmenes
docker compose -f compose.dev.yaml --profile runtime down            # elimina contenedores y red; conserva volúmenes
docker compose -f compose.dev.yaml --profile runtime up -d --wait    # rearranque (pasa por el bootstrap)
```

Ninguno de los dos borra datos. Lo que borra datos es `-v` (ver RESET). Tras rearrancar, `bootstrap` ve `DB_COMPATIBLE` +
`INSTALL_COMPLETE` y no reinstala.

## RESET DEV (destructivo)

> **DESTRUCTIVO. SOLO DEV.** Borra la base de datos, los `uploads`, los `downloads` y los índices de Elasticsearch **del
> proyecto Docker seleccionado**. No hay vuelta atrás. No es un comando cotidiano.

```bash
docker compose -f compose.dev.yaml --profile runtime down -v
```

Antes de ejecutarlo, comprueba **qué proyecto** vas a borrar (sin `-p`/`COMPOSE_PROJECT_NAME` es `archivo-historico`, la
instancia DEV normal):

```bash
docker compose -f compose.dev.yaml --profile runtime config --format json | grep -m1 '"name"'
```

Elimina exactamente: contenedores del proyecto, su red y `percona_data`, `elasticsearch_data`, `uploads_data`,
`downloads_data`. No elimina imágenes, el checkout ni otros proyectos Docker. Después, el siguiente `up -d --wait` es una
instalación completamente nueva (`DB_FRESH` → `tools:install` una vez → READY). RESET no es una reparación parcial: es la
destrucción explícita de todo el estado.

Este flujo (incluido el `down -v` real y la segunda instalación) está validado por `scripts/test-fresh-e2e.sh`, siempre
sobre un proyecto E2E aislado.

Nunca uses `docker system prune` ni `docker volume prune` como sustituto.

## Segunda instancia aislada y prueba E2E

`name: archivo-historico` está fijo en el compose, así que **cualquier otro checkout comparte proyecto Docker con la
DEV** salvo que lo sobrescribas. La precedencia real (verificada) es `-p` > `COMPOSE_PROJECT_NAME` > `name:`. Para una
instancia paralela hacen falta, como mínimo, un proyecto y un puerto distintos:

```bash
export ATOM_WEB_PORT=18080
docker compose -f compose.dev.yaml -p archivo-historico-otra --profile runtime up -d --wait
```

Aviso: los tags de imagen (`archivo-historico/atom:2.10.2`, `archivo-historico/nginx:2.10.2`) son fijos y no dependen del
proyecto. Un `build` desde otro checkout **reasigna esos tags** a las imágenes nuevas (los contenedores DEV en marcha
siguen con la imagen anterior, pero un futuro `up`/recreación usará la nueva). `scripts/test-fresh-e2e.sh` lo evita
renombrando las imágenes con un override propio.

```bash
scripts/test-fresh-e2e.sh          # tarda varios minutos; E2E_KEEP=1 conserva checkout y proyecto para depurar
```

Hace un `git clone` del commit actual + `git submodule update --init --recursive` en un directorio temporal, arranca un
proyecto `archivo-historico-e2e-<id>` en un puerto loopback libre, verifica el fresh install, hace el RESET y verifica un
segundo fresh install. Comprueba antes del RESET que ningún recurso E2E coincide con los de la DEV y al final que la DEV
queda idéntica. Limpia solo lo suyo. Requiere red (submódulo) y unos minutos.

## Nginx

`docker/nginx/nginx.conf` se monta **solo lectura** en el contenedor: editarlo en el host no se aplica solo. Tras editarlo:

```bash
docker compose -f compose.dev.yaml --profile runtime restart nginx
```

Los estáticos (`dist`, `images`, `css`, `js`) van dentro de la imagen `nginx`; cambiar el Dockerfile o `upstream/atom`
requiere `build` y recrear `nginx`. Un 200 de Nginx no implica que AtoM funcione: usa `scripts/web-ready.sh`.

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
| `config` de AtoM | regenerable | El entrypoint la genera desde el entorno en cada arranque |
| `dist` y estáticos | derivado | Vienen de la imagen; no hay volumen |
| Memcached, Gearmand | efímero | Sin volumen a propósito |

## Pendiente (fuera de este runbook)

Producción, TLS, backup/restore, monitorización, CI/CD, seed de datos, migraciones y personalización (tema, API): no
están decididos y no se describen aquí.
