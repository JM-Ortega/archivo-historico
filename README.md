# Archivo Histórico

Entorno de desarrollo (DEV) para un archivo histórico basado en [AtoM](https://www.accesstomemory.org/)
(Access to Memory, de Artefactual Systems).

- **Baseline AtoM:** v2.10.2, incluida como submódulo Git sin modificar en `upstream/atom`
  (commit `02a70b8a4b23a805256abd0a14cd0f93e311a581`). La imagen se construye con el `Dockerfile` upstream.
- **Runtime DEV** (`compose.dev.yaml`): Percona 8.4, Elasticsearch 7.10 (OSS), Memcached, Gearmand, `bootstrap`
  (instalación inicial segura), `atom` (PHP-FPM), `atom_worker` (jobs de AtoM) y `nginx`.
- Este documento es la entrada rápida. Operación, diagnóstico y RESET: [docs/dev-runbook.md](docs/dev-runbook.md).

Solo DEV. Producción, TLS, backups y CI/CD **no** están definidos todavía.

## Prerrequisitos

- `git`
- Docker y Docker Compose v2 (probado con Docker 29.8 y Compose v5.5.1)
- `curl` (lo usa `scripts/web-ready.sh`)
- Red para descargar imágenes y el submódulo (`https://github.com/artefactual/atom.git`)
- Unos pocos GB de RAM libres (Elasticsearch usa 640 MB de heap; `tools:install` admite hasta 2 GB de PHP)
- El puerto `8080` libre en loopback (o elige otro con `ATOM_WEB_PORT`)

**Plataforma:** el flujo de este documento (incluido el E2E de checkout limpio y RESET) se validó en Linux/WSL2.
Windows nativo todavía no se ha validado. No se ha fijado WSL como requisito obligatorio del proyecto.

## Clonar

El submódulo es imprescindible: sin él no hay nada que construir.

```bash
git clone --recurse-submodules <URL-DEL-REPO> archivo-historico
cd archivo-historico
```

Si ya clonaste sin `--recurse-submodules`:

```bash
git submodule update --init --recursive
```

`git -C upstream/atom status --porcelain` debe salir vacío: el submódulo no se modifica.

## Arrancar DEV

Todos los comandos desde la raíz del repo. La primera vez hay que construir las imágenes:

```bash
docker compose -f compose.dev.yaml --profile runtime build
docker compose -f compose.dev.yaml --profile runtime up -d --wait
```

En el primer arranque la base de datos está vacía: `bootstrap` ejecuta `tools:install` **una sola vez** y crea el
administrador. Arranques posteriores no reinstalan nada (una BD ya instalada no se toca). `up` termina cuando todos los
servicios están `healthy` y `bootstrap` ha terminado con éxito.

- **URL local:** <http://localhost:8080> (solo loopback, `127.0.0.1`)
- **Administrador DEV:** `admin@example.com` / `admin_dev_12345` (credenciales locales de desarrollo, sin valor fuera
  de tu máquina; se pueden cambiar con `ATOM_ADMIN_EMAIL` / `ATOM_ADMIN_PASSWORD` **antes del primer arranque**)

Solo `nginx` publica un puerto. PHP-FPM, la BD, Elasticsearch, Gearmand y Memcached no son accesibles desde el host.

## Comprobar que está READY

```bash
docker compose -f compose.dev.yaml --profile runtime ps
scripts/web-ready.sh --wait
```

`web-ready.sh` exige HTTP 200 **y** el marcador de AtoM (cookie `atom_culture`): un 200 de Nginx sin AtoM detrás
no cuenta. Con otro puerto: `ATOM_WEB_PORT=<puerto> scripts/web-ready.sh --wait`.

## Parar y volver a arrancar (sin borrar datos)

```bash
docker compose -f compose.dev.yaml --profile runtime stop      # pausa; conserva contenedores y datos
docker compose -f compose.dev.yaml --profile runtime up -d --wait   # vuelve a arrancar

docker compose -f compose.dev.yaml --profile runtime down      # elimina contenedores y red; CONSERVA los volúmenes
```

Tanto `stop` como `down` (sin `-v`) conservan la base de datos y los ficheros subidos. Para volver a arrancar usa
siempre `up -d --wait`, que vuelve a pasar por el gate de `bootstrap`.

## Dónde vive el estado

Volúmenes Docker del proyecto `archivo-historico` (`docker volume ls`):

| Volumen | Contenido | Naturaleza |
| --- | --- | --- |
| `archivo-historico_percona_data` | base de datos | **autoritativo** |
| `archivo-historico_uploads_data` | objetos digitales subidos | **autoritativo** |
| `archivo-historico_downloads_data` | informes y exportaciones | persistente por prudencia |
| `archivo-historico_elasticsearch_data` | índices de búsqueda | derivado (reconstruible desde la BD) |

La configuración de AtoM se regenera en cada arranque y el código y los estáticos vienen de la imagen: no son estado.

## Qué NO hacer

- **No ejecutes `down -v`** salvo que quieras borrar TODO el estado DEV a propósito. Es el RESET DEV: destructivo e
  irreversible. Ver [RESET DEV](docs/dev-runbook.md#reset-dev-destructivo).
- No uses `docker system prune` ni `docker volume prune`: pueden llevarse volúmenes de otros proyectos.
- No modifiques `upstream/atom` (es el baseline exacto de AtoM).
- No ejecutes `docker compose up` sin `--profile runtime` esperando la web: sin el perfil solo sube la infraestructura.
- No publiques puertos de servicios internos ni cambies el bind de `nginx` a `0.0.0.0` en DEV.

## Pruebas

Los scripts de `scripts/test-*.sh` son pruebas de integración contra Docker. Los `test-db-probe`, `test-bootstrap`,
`test-runtime`, `test-web` y `test-worker` operan sobre el proyecto DEV real sin borrar estado (nunca `down -v`).
`scripts/test-fresh-e2e.sh` es la prueba de checkout limpio + RESET: crea su propio clon, proyecto Docker
(`archivo-historico-e2e-<id>`), puerto e imágenes, y **no toca** la instancia DEV normal. Ver el runbook.
