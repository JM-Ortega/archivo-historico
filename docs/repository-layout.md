# Layout del repositorio

Fija **dónde vive cada cosa** y quién es dueño de cada superficie, para que el trabajo en paralelo no mezcle
responsabilidades. Es un contrato de fronteras: no implementa ninguna de las capacidades futuras que menciona.
Entrada rápida: [README](../README.md). Operación DEV: [runbook](dev-runbook.md).

## Mapa

| Ubicación | Estado | Contenido |
| --- | --- | --- |
| `upstream/atom/` | existe | AtoM v2.10.2 exacto (submódulo Git). **READ-ONLY** |
| `compose.yaml`, `docker/` | existe | Runtime / integración local DEV |
| `scripts/` | existe | Herramientas **transversales** del proyecto |
| `docs/` | existe | Documentación durable |
| `migration/` | existe (solo `README.md`) | Pipeline y tooling de migración. Ver [migration/README.md](../migration/README.md) |
| `plugins/arUnicaucaB5Plugin/` | existe | Theme institucional de AtoM (source, wrapper de build y tests del theme). Ver [theme-development.md](theme-development.md) |
| `config/atom/` | existe | Configuración de AtoM **gestionada por el proyecto**: entrypoint runtime (`runtime-config.sh`: cultura, timezone, secreto), proveedor del secreto DEV (`dev-secrets.sh`), desired state y reconcile (`required-plugins.conf`, `reconcile*.{sh,php}`) y `tests/`. Ver [Configuración crítica](dev-runbook.md#configuración-crítica-de-atom-cultura-timezone-secreto-csrf-y-updates) y [Reconcile](dev-runbook.md#reconcile-de-plugins-desired-state) |
| `deploy/` | **reservada** | Foundation y automatización de despliegue |

"Reservada" significa que la ruta es la canónica pero el directorio **no existe todavía**: se crea con el primer
contenido real, no antes. No se crean carpetas vacías (`deploy/` sigue reservada; `config/atom/` ya existe).

## Ownership

| Frente | Superficie propia |
| --- | --- |
| Theme | `plugins/arUnicaucaB5Plugin/` |
| Migration | `migration/` |
| Config / reconcile | `config/atom/` (desired state + reconcile + sus tests) |
| Deployment | `deploy/` |
| Runtime / plataforma compartida | `compose.yaml`, `docker/`, `scripts/` transversales |
| Upstream | ninguno: `upstream/atom` no se modifica desde este proyecto |

Cada frente trabaja dentro de su superficie. Tocar una superficie compartida (runtime, `scripts/`, `.gitignore`, etc.)
**no está prohibido**, pero es un **cambio de integración**: se hace de forma explícita y separada, no de pasada dentro
de una feature.

## Preguntas frecuentes

- **¿Dónde va el código del theme?** En `plugins/arUnicaucaB5Plugin/`. Cómo se monta, compila y sirve (bind RO, `theme_build`,
  `theme_dist`, Nginx) está en [theme-development.md](theme-development.md). Su tooling y sus tests viven dentro del plugin
  (`tools/`, `tests/`), no en `scripts/`.
- **¿Dónde va migration?** En `migration/`. Contrato en [migration/README.md](../migration/README.md).
- **¿Dónde vive reconcile/config?** En `config/atom/` (desired state gestionado por el proyecto). Hoy declara un
  par de propiedades de `setting`/plugins (`arUnicaucaB5Plugin` habilitado y `check_for_updates = 0`) más la configuración runtime crítica. No hay seed ni gestión de la tabla `setting` en general. Su tooling y sus
  tests viven ahí (`reconcile*.{sh,php}`, `runtime-config.sh`, `tests/`), no en `scripts/`.
- **¿Dónde irá deployment?** En `deploy/`. Fuera de esta foundation: producción, TLS, CI/CD, Ansible/Terraform, etc.
- **¿Dónde van datos reales locales?** En `migration/local/`, ignorado por Git (ver abajo).
- **¿Qué no se modifica?** `upstream/atom/`. Comprobación: `git -C upstream/atom status --porcelain` debe salir vacío.

## Qué vive en `scripts/`

`scripts/` **no es un cajón general**.

- Herramienta **transversal** del proyecto (la usan varios frentes o el runtime) → `scripts/`.
  Ejemplos actuales: `web-ready.sh`, `db-probe.php`, `bootstrap.sh`, `test-*.sh`.
  Ejemplo de lo que NO va aquí: el wrapper de build y la prueba del theme (`plugins/arUnicaucaB5Plugin/{tools,tests}/`).
- Herramienta **específica de un dominio** → vive con ese dominio: un parser exclusivo de migración va bajo
  `migration/`, un helper exclusivo de despliegue bajo `deploy/`.

Los scripts actuales no se reorganizan sin una razón real.

## Datos reales y Git

Nunca deben acabar en Git: PDF/Word/Excel reales del Archivo, el corpus completo, extractos con datos personales,
credenciales ni temporales locales.

- Los datos reales de migración van en `migration/local/`, ignorado por `.gitignore` (`/migration/local/`).
- La protección es **por boundary, no por extensión**: `*.pdf`, `*.docx` y `*.xlsx` **no** se ignoran globalmente,
  porque puede haber documentos legítimos versionados en otros contextos (p. ej. fixtures aprobados).
- `.env`, `.env.*` (salvo `.env.example`) y `*.key` están ignorados.
- El secreto CSRF local de DEV vive en un volumen Docker (`atom_secrets`), nunca en el checkout.

## Host y portabilidad

| Entorno | Papel |
| --- | --- |
| WSL2 + Docker Desktop | Entorno de **referencia**, ya utilizado y validado |
| Windows 10/11 + Docker Desktop + PowerShell | **Target a soportar**. **Aún no validado**: se probará desde un host Windows real |
| Git Bash | Puede existir (Git for Windows). **No es requisito** del proyecto |

Regla: **no añadir dependencias al host cuando la operación pueda ejecutarse razonablemente dentro del runtime
Docker.** Nadie debería tener que instalar a mano PHP, Node/npm, Webpack, cliente MySQL ni tooling de AtoM si Docker
puede aportarlo. No se mantienen parejas `foo.sh` / `foo.ps1` solo por compatibilidad.

El theme respeta la regla: Webpack/Node corren dentro de la imagen AtoM (`theme_build`); no hay Node/npm ni PHP en el host.
Windows nativo sigue sin validarse también para este flujo.

Punto abierto (no resuelto aquí): los scripts `scripts/*.sh` actuales se ejecutan en el host con Bash (y
`web-ready.sh` además con `curl`). Cómo se cubre eso desde PowerShell se decidirá con la validación en Windows real.

### Fin de línea

`.gitattributes` fija LF para lo que se ejecuta o se monta en contenedores Linux (`*.sh`, `*.php`, `*.cnf`, `*.conf`,
`Dockerfile`, `*.yaml`/`*.yml`, y el source del theme: `*.js`, `*.scss`), de modo que el checkout de **este** repo no
depende de `core.autocrlf` ni `core.eol` del usuario. `.editorconfig` fija solo codificación, EOL, salto final y
espacios sobrantes; no impone estilos por lenguaje.

`upstream/atom` es un submódulo externo, read-only, y no lleva esa política: en un checkout Windows con
`core.autocrlf=true` su working tree llega a Docker en CRLF (afecta a cualquier script suyo invocado por shebang,
no solo `docker/entrypoint.sh`). Como no se puede ni se debe tocar `upstream/atom` para corregir esto, la imagen
AtoM del proyecto se construye en dos capas (`atom_upstream` + `docker/atom/Dockerfile`, ver
[runbook](dev-runbook.md#reconstruir-imágenes)): la segunda normaliza a LF, dentro de la imagen, únicamente los
ficheros de texto con shebang. `upstream/atom` en el host sigue intacto; la corrección vive solo en la
representación de la imagen final.

## Ramas

`main` es la rama estable: no recibe trabajo directo. `develop` es la rama de integración: recibe el trabajo en
curso. Las ramas temporales (`feat/*`, `fix/*`, `refactor/*`) son de trabajo y se integran contra `develop`.

Flujo normal:

```
feat/* | fix/* | refactor/*
          ↓
       develop
          ↓ PR
         main
```

- Push directo a `develop` está permitido; no exige PR. GitHub bloquea su borrado y el force-push.
- La promoción `develop → main` se hace mediante PR, con merge method de merge commit. La PR debe originarse desde
  `develop` en este mismo repositorio (política de origen verificada en CI).
- `main` está protegido: borrado y force-push bloqueados, PR obligatoria, CI requerido (checks mínimos y política de
  origen).
