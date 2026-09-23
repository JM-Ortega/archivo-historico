# Archivo Histórico

Entorno de desarrollo (DEV) para un archivo histórico basado en [AtoM](https://www.accesstomemory.org/)
(Access to Memory, de Artefactual Systems).

- **Baseline AtoM:** v2.10.2, incluida como submódulo Git sin modificar en `upstream/atom`
  (commit `02a70b8a4b23a805256abd0a14cd0f93e311a581`). La imagen se construye con el `Dockerfile` upstream.
- **Runtime DEV** (`compose.yaml`, solo desarrollo local): Percona 8.4, Elasticsearch 7.10 (OSS), Memcached, Gearmand, `bootstrap`
  (instalación inicial segura), `dev_secrets` (secreto CSRF local), `reconcile` (plugins requeridos y `check_for_updates = 0`), `theme_build` (build del theme), `atom` (PHP-FPM), `atom_worker` (jobs de AtoM) y `nginx`.
- **Theme institucional:** `plugins/arUnicaucaB5Plugin/` (plugin de AtoM propio, basado en el skeleton oficial `arThemeB5Plugin` y extensión de `arDominionB5Plugin`). Ciclo DEV
  (editar → build → refresh) en [docs/theme-development.md](docs/theme-development.md).
- Este documento es la entrada rápida. Operación, diagnóstico y RESET: [docs/dev-runbook.md](docs/dev-runbook.md).
- Dónde vive cada cosa (theme, migration, config, deployment, `scripts/`) y qué no se toca: [docs/repository-layout.md](docs/repository-layout.md).

Solo DEV. Producción, TLS, backups y CI/CD **no** están definidos todavía.

## Prerrequisitos

- `git`
- Docker y Docker Compose v2 (probado con Docker 29.8 y Compose v5.5.1)
- `curl` (lo usa `scripts/web-ready.sh`)
- Red para descargar imágenes y el submódulo (`https://github.com/artefactual/atom.git`)
- Unos pocos GB de RAM libres (Elasticsearch usa 640 MB de heap; `tools:install` admite hasta 2 GB de PHP)
- El puerto `8080` libre en loopback (o elige otro con `ATOM_WEB_PORT`)

**Plataforma:** el flujo de este documento (incluido el E2E de checkout limpio y RESET) se validó en WSL2 + Docker
Desktop, el entorno de referencia. El target a soportar es Windows 10/11 + Docker Desktop + PowerShell; **todavía no se
ha validado** en Windows nativo (el ciclo del theme tampoco: no requiere Node/npm en el host, pero falta el smoke real). Git Bash no es un requisito. Detalle en [docs/repository-layout.md](docs/repository-layout.md#host-y-portabilidad).

## Primera vez

```bash
git clone --recurse-submodules https://github.com/JM-Ortega/archivo-historico.git archivo-historico
cd archivo-historico
docker compose up -d --wait
```

Abre <http://localhost:8080>. Un solo comando: Compose construye las imágenes que faltan (la primera vez tarda varios
minutos), arranca la infraestructura, ejecuta `bootstrap`, `reconcile` (habilita el plugin del theme) y `theme_build` (compila el theme) y levanta `atom`, `atom_worker` y
`nginx`. `up` termina cuando todos los servicios están `healthy` y `bootstrap`, `reconcile` y `theme_build` han terminado con éxito.

- **Administrador DEV:** `admin@example.com` / `admin_dev_12345` (credenciales locales de desarrollo, sin valor fuera
  de tu máquina; se pueden cambiar con `ATOM_ADMIN_EMAIL` / `ATOM_ADMIN_PASSWORD` **antes del primer arranque**).
  Para overrides habituales (puerto, admin, timezone, título), copia [`.env.example`](.env.example) a `.env` y
  descomenta/ajusta únicamente las variables que necesites.
- En el primer arranque la base de datos está vacía: `bootstrap` ejecuta `tools:install` **una sola vez**. Arranques
  posteriores no reinstalan nada.
- Solo `nginx` publica un puerto (`127.0.0.1:8080`; otro con `ATOM_WEB_PORT`). La BD, Elasticsearch, Gearmand,
  Memcached y PHP-FPM no son accesibles desde el host.

Si clonaste sin `--recurse-submodules`: `git submodule update --init --recursive` (el submódulo es imprescindible).
`git -C upstream/atom status --porcelain` debe salir vacío: el submódulo no se modifica.

## Día a día

Desde la raíz del repo:

```bash
docker compose up -d --wait     # arrancar / reconciliar (también tras un reinicio de la máquina)
docker compose stop             # parar conservando contenedores y datos
```

| Quiero… | Comando |
| --- | --- |
| Ver el estado | `docker compose ps` |
| Ver logs | `docker compose logs -f` (o `logs -f atom`) |
| Comprobar que la web es AtoM (READY) | `scripts/web-ready.sh --wait` |
| Recompilar el theme tras editar su SCSS/JS | `docker compose run --rm theme_build` (ver [theme](docs/theme-development.md)) |
| Recompilar el theme automáticamente mientras edito (opt-in, DEV) | `docker compose run --rm theme_watch` (ver [theme](docs/theme-development.md#editar-scss--js)) |
| Reaplicar/verificar los plugins requeridos | `docker compose run --rm reconcile` (ver [runbook](docs/dev-runbook.md#reconcile-de-plugins-desired-state)) |
| Eliminar contenedores y red, **conservando** los datos | `docker compose down` |

`stop` y `down` (sin `-v`) conservan la base de datos y los ficheros subidos; `up -d --wait` siempre vuelve a pasar por
el gate de `bootstrap`. `up` **no** reconstruye una imagen ya existente: tras cambiar `upstream/atom` o `docker/nginx`
usa `docker compose up -d --build --wait` (ver [runbook](docs/dev-runbook.md#reconstruir-imágenes)).

`web-ready.sh` exige HTTP 200 **y** el marcador de AtoM (cookie `atom_culture`): un 200 de Nginx sin AtoM detrás
no cuenta. Con otro puerto: `ATOM_WEB_PORT=<puerto> scripts/web-ready.sh --wait`.

## Dónde vive el estado

Volúmenes Docker del proyecto `archivo-historico` (`docker volume ls`):

| Volumen | Contenido | Naturaleza |
| --- | --- | --- |
| `archivo-historico_percona_data` | base de datos | **autoritativo** |
| `archivo-historico_uploads_data` | objetos digitales subidos | **autoritativo** |
| `archivo-historico_downloads_data` | informes y exportaciones | persistente por prudencia |
| `archivo-historico_elasticsearch_data` | índices de búsqueda | derivado (reconstruible desde la BD) |
| `archivo-historico_theme_dist` | bundles compilados del theme | derivado (lo reconstruye `theme_build`; sin backup) |
| `archivo-historico_atom_secrets` | secreto CSRF local de DEV | secreto de entorno (`down -v` lo regenera; nunca en Git) |

La configuración de AtoM se regenera en cada arranque y el código y los estáticos de AtoM vienen de la imagen: no son estado.
Configuración crítica del proyecto (cultura `es`, timezone `America/Bogota`, secreto CSRF real y `check_for_updates = 0`):
[runbook](docs/dev-runbook.md#configuración-crítica-de-atom-cultura-timezone-secreto-csrf-y-updates). El timezone se cambia con `ATOM_TIMEZONE` (una sola variable para PHP y Symfony).
El source del theme vive en `plugins/arUnicaucaB5Plugin/` (repo) y se monta en los contenedores.

## Qué NO hacer

- **No ejecutes `down -v`** salvo que quieras borrar TODO el estado DEV a propósito (también el secreto CSRF local: el siguiente `up` genera uno nuevo). Es el RESET DEV: destructivo e
  irreversible. Ver [RESET DEV](docs/dev-runbook.md#reset-dev-destructivo).
- No uses `docker system prune` ni `docker volume prune`: pueden llevarse volúmenes de otros proyectos.
- No modifiques `upstream/atom` (es el baseline exacto de AtoM). El theme se desarrolla en `plugins/arUnicaucaB5Plugin/`.
- No publiques puertos de servicios internos ni cambies el bind de `nginx` a `0.0.0.0` en DEV.

## Pruebas

Los scripts de `scripts/test-*.sh` son pruebas de integración contra Docker (la del theme vive con él:
`plugins/arUnicaucaB5Plugin/tests/test-theme.sh`; las del reconcile y de la configuración crítica, con su desired state:
`config/atom/tests/test-reconcile-plugins.sh` y `config/atom/tests/test-critical-config.sh`, esta última sobre un proyecto Docker aislado). Los `test-db-probe`, `test-bootstrap`,
`test-runtime`, `test-web` y `test-worker` operan sobre el proyecto DEV real sin borrar estado (nunca `down -v`).
`scripts/test-fresh-e2e.sh` es la prueba de checkout limpio + RESET: crea su propio clon, proyecto Docker
(`archivo-historico-e2e-<id>`), puerto e imágenes, y **no toca** la instancia DEV normal. Ver el runbook.

## Licencia

El código propio de este proyecto se distribuye bajo [AGPL-3.0-or-later](LICENSE). AtoM (`upstream/atom`) es un
proyecto externo incluido como submódulo Git sin modificar: conserva su propia licencia y notices, ver
[upstream/atom/LICENSE](upstream/atom/LICENSE).
