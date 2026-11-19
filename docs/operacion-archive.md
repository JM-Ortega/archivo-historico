# Operación y despliegue de Archive

Guía mínima para que un integrante autorizado publique una revisión de código en
Archive (VPS con AtoM 2.10.2 nativo) desde GitHub Actions, compruebe el resultado
y revise el estado de la aplicación. Los detalles técnicos están en
[build-artifact.md](build-artifact.md) (qué contiene el paquete) y
[deploy-artifact.md](deploy-artifact.md) (qué hace el actualizador, permisos,
fallos, identidad `atom-deploy`); aquí no se repiten.

Requisitos: permiso para lanzar workflows en `JM-Ortega/archivo-historico` y, para
la parte de servidor, una cuenta de administración en Archive con `sudo`. Ninguna
credencial, clave ni dominio forma parte de esta guía.

## 1. Publicar una revisión

### Workflow

- Fichero: [`.github/workflows/ci.yml`](../.github/workflows/ci.yml), nombre **CI**.
- En GitHub: pestaña *Actions* → *CI*.
- Jobs: `Minimal checks`, `Main source policy` (solo PR a `main`), `Build artifact`
  y `Publish to Archive`. Los `push` y `pull_request` construyen y validan el
  artefacto pero **nunca** publican: solo una ejecución manual
  (`workflow_dispatch`) llega a `Publish to Archive`.

### Qué revisión se publica

Solo se puede publicar **la punta actual de `main`** (`github.sha` de la ejecución).
No hay selector de commit: el workflow rechaza cualquier otra rama
(`Manual publication is only allowed from refs/heads/main`) y cualquier commit que
haya dejado de ser la punta (`Commit is not the current tip of main`).

Una revisión se considera aprobada cuando llegó a `main` mediante la PR
`develop → main` (ver [repository-layout.md](repository-layout.md#ramas)) y el run
de CI del `push` a `main` terminó en verde. Antes de lanzar:

```bash
git fetch origin
git log -1 --format='%H %s' origin/main
gh run list --repo JM-Ortega/archivo-historico --workflow ci.yml --branch main --event push --limit 3 \
  --json headSha,conclusion,createdAt
```

El `headSha` del run más reciente debe coincidir con `origin/main` y su
`conclusion` debe ser `success`.

Para volver a una revisión anterior no sirve el workflow: es una
[recuperación manual de código](deploy-artifact.md#recuperación-manual-de-código).

### `check` frente a `deploy`

| Acción | Qué ocurre en Archive |
| --- | --- |
| `check` (por defecto) | Envía el artefacto y ejecuta el actualizador con `--check-only`: valida paquete, instalación, sintaxis PHP y unidades; informa de desviaciones de permisos. **No detiene servicios ni cambia ficheros.** |
| `deploy` | Hace primero el mismo `check` y, si pasa, publica: `reset-failed` del worker, parada de Nginx, PHP-FPM y `atom-worker`, copia de las superficies del paquete, `symfony cc` y arranque de los tres servicios. |

Recomendación: lanzar `check`, revisar su salida y, después, `deploy` sobre el
mismo commit. Solo se admite una publicación a la vez (grupo de concurrencia
`archive-publish` y bloqueo en el servidor).

### Ejecutar

Interfaz: *Actions* → *CI* → *Run workflow* → *Use workflow from*: `main` →
*action*: `check` o `deploy` → *Run workflow*.

Con `gh`:

```bash
gh workflow run ci.yml --repo JM-Ortega/archivo-historico --ref main -f action=check
gh run list --repo JM-Ortega/archivo-historico --workflow ci.yml --event workflow_dispatch --limit 3
gh run watch <RUN_ID> --repo JM-Ortega/archivo-historico
```

No cancelar un run mientras ejecuta el paso `Remote deploy`: puede dejar los
servicios detenidos.

### Comprobar que se publicó la revisión correcta

1. Commit del run y resultado:

   ```bash
   gh run view <RUN_ID> --repo JM-Ortega/archivo-historico --json headSha,headBranch,event,conclusion
   ```

   Debe mostrar el SHA esperado, `main`, `workflow_dispatch` y `success`.
2. Salida del job `Publish to Archive` (el ID del job aparece en `gh run view <RUN_ID>`):

   ```bash
   gh run view <RUN_ID> --repo JM-Ortega/archivo-historico --job <JOB_ID> --log \
     | grep -E 'Validated source|Permission model|Check only|OK: installed source'
   ```

   - `check`: `Validated source <SHA>; <N> files` y `Check only: no services stopped and no installed files changed`.
   - `deploy`: además `OK: installed source <SHA>; archive SHA256 <SHA256>`.
     El resumen del run muestra `Mode deploy: <SHA> (published)`; en `check`,
     `(checked only)` y el paso `Remote deploy` aparece omitido.
3. Contraste independiente (opcional, mientras el artefacto se conserva, 14 días):
   el bundle del theme servido debe ser el del artefacto.

   ```bash
   gh run download <RUN_ID> --repo JM-Ortega/archivo-historico --name atom-artifact-<SHA> --dir tmp/check-<SHA>
   (cd tmp/check-<SHA> && sha256sum -c *.sha256)
   tar -tzf tmp/check-<SHA>/archivo-historico-<SHA>.tar.gz | grep -E 'arUnicaucaB5Plugin\.bundle\.[0-9a-f]+\.js$'
   # en Archive:
   curl -sS http://127.0.0.1:8080/ | grep -oE '/dist/js/arUnicaucaB5Plugin\.bundle\.[0-9a-f]+\.js'
   ```

El runtime no conserva el manifiesto: la evidencia de qué revisión está instalada
es el log del último run `deploy` con éxito.

### Identificar un fallo

```bash
gh run list --repo JM-Ortega/archivo-historico --workflow ci.yml --event workflow_dispatch --limit 5
gh run view <RUN_ID> --repo JM-Ortega/archivo-historico --log-failed
```

| Falla en | Significado | Estado de Archive |
| --- | --- | --- |
| `Publication guard` / `Verify checkout, main tip…` | Rama distinta de `main` o `main` avanzó tras lanzar | Intacto; relanzar sobre la punta actual |
| `Build artifact` / `Rebuild and compare` / `Verify package integrity` | Problema de construcción o reproducibilidad del commit | Intacto; corregir en `develop` |
| `Configure SSH` | Secretos `ARCHIVE_SSH_*` ausentes o mal formados | Intacto; avisar a un administrador |
| `Remote check` | El actualizador rechazó paquete o instalación (salida `atom-publish: ERROR` o del actualizador) | Intacto: el `check` no detiene nada |
| `Remote deploy` | Error durante la publicación | **Puede tener los tres servicios detenidos** (`ERROR: update incomplete; application services left stopped`). Ver [Resultado y fallos](deploy-artifact.md#resultado-y-fallos) |

`atom-publish: ERROR: another publication is in progress` indica otra publicación
en curso: esperar y relanzar.

## 2. Comprobar Archive

Comandos para ejecutar en el VPS con la cuenta de administración. Ninguno modifica
el sistema.

### Servicios

```bash
systemctl is-active nginx php8.3-fpm atom-worker
systemctl status nginx php8.3-fpm atom-worker --no-pager
systemctl show -p NRestarts,ActiveEnterTimestamp atom-worker
```

Los tres deben estar `active`. `atom-worker` usa `Restart=on-failure` con
`StartLimitBurst=3` en `StartLimitIntervalSec=24h`: reinicios repetidos indican un
fallo del worker que hay que diagnosticar, no relanzar a ciegas.

### Logs

Requieren `sudo`. Mostrar solo las últimas líneas y no copiar fuera del equipo
fragmentos con correos, IP, cookies o tokens.

```bash
sudo journalctl -u atom-worker -n 100 --no-pager
sudo journalctl -u nginx -u php8.3-fpm --since '1 hour ago' --no-pager
sudo tail -n 50 /var/log/nginx/error.log
sudo tail -n 50 /var/log/php8.3-fpm.log
sudo ls -l /usr/share/nginx/atom/log/     # logs de Symfony/AtoM; revisar con tail el que corresponda
```

No mostrar ni copiar `config/config.php`, `config/propel.ini`,
`apps/qubit/config/settings.yml*` ni `config/appChallenge.yml`: contienen
credenciales o secretos.

### HTTP y HTTPS

```bash
# Nginx local → AtoM: 200 y cookie atom_culture (un 200 sin la cookie no es AtoM)
curl -sS -o /dev/null -D - http://127.0.0.1:8080/ | grep -iE '^(HTTP|set-cookie: atom_culture)'
# Bundle del theme referenciado por la portada: 200
B=$(curl -sS http://127.0.0.1:8080/ | grep -oE '/dist/js/arUnicaucaB5Plugin\.bundle\.[0-9a-f]+\.js' | head -1)
curl -sS -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:8080$B"
# TLS del origen (certificado de origen, por eso -k): 200
curl -sSk -o /dev/null -w '%{http_code}\n' https://127.0.0.1/
```

Desde fuera, la URL pública debe responder 200 en HTTPS con certificado válido:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://<DOMINIO_PUBLICO>/
```

### Funcionamiento básico tras publicar

En un navegador, con la URL pública:

1. La portada carga con el theme institucional (sin estilos rotos ni errores de
   JS en consola).
2. El inicio de sesión funciona con la propia cuenta (dominio `@unicauca.edu.co`).
3. Se puede navegar y buscar descripciones archivísticas y abrir una.
4. Páginas estáticas (Privacidad, «Acerca de») se muestran.

Solo lectura: no crear, editar ni borrar registros para comprobar.

### ¿Despliegue o problema preexistente?

El despliegue cambia el plugin `arUnicaucaB5Plugin`, `dist/` y tres plantillas
generadas, vacía `cache/` y reinicia los servicios. El plugin interviene en la
interfaz y también en comportamientos de la aplicación (por ejemplo, el inicio de
sesión), así que un fallo no se atribuye por el área en que aparece, sino por
evidencia:

1. **Momento:** hora del primer error en los logs frente a la hora del run
   `deploy`. Si ya ocurría antes del run, es preexistente.
2. **Origen en los logs:** trazas que apuntan a ficheros de
   `plugins/arUnicaucaB5Plugin/` o a bundles de `dist/` que no cargan señalan la
   revisión publicada.
3. **Reproducción:** comparar la misma operación en DEV con el commit publicado y
   con el anterior. Si el fallo aparece solo con el commit nuevo, viene del cambio
   publicado; si aparece con ambos, es anterior al despliegue.
4. **Estado de servicios:** servicios detenidos o reiniciándose tras el run
   remiten a [Resultado y fallos](deploy-artifact.md#resultado-y-fallos).

Si con esto no queda claro, tratarlo como posible efecto del despliegue y pedir
revisión antes de volver a publicar.

## 3. Límites operativos

**Qué actualiza el despliegue:** solo las superficies del paquete
([Contenido y límites](build-artifact.md#contenido-y-límites)), la convergencia de
permisos y la caché (ver [Aplicación](deploy-artifact.md#aplicación)).

**Qué queda fuera:** BD, `uploads/`, `downloads/`, índices de búsqueda,
configuración del entorno (`config/config.php`, `propel.ini`, `settings.yml`,
`appChallenge.yml`), unidades systemd, Nginx/TLS, otros plugins y el estado
deseado de `config/atom/` (el paquete no lo incluye).

**Operaciones distintas del DEPLOY** (contrato en
[deploy-artifact.md](deploy-artifact.md#contrato-de-actualización)):

| Cambio | Operación |
| --- | --- |
| Instalación inicial de AtoM | INSTALL, una vez, como `root` |
| Plugins habilitados, ajustes, términos, Privacidad/«Acerca de» (`config/atom/`) | RECONCILE, explícito, fuera del DEPLOY |
| Otra versión de AtoM, submódulo `upstream/atom`, esquema o migración de datos | UPGRADE: no soportado por el despliegue |
| `scripts/deploy-artifact.py` o `scripts/atom-publish.py` | Reinstalación manual por un administrador ([Publicación restringida](deploy-artifact.md#publicación-restringida-desde-ci)) |
| Servicios, Nginx, TLS, SSH, firewall, secretos | Administración del servidor, fuera de este procedimiento |
| Volver a una revisión anterior | [Recuperación manual de código](deploy-artifact.md#recuperación-manual-de-código) |

Para comprobar si el wrapper y el actualizador instalados son los del repositorio,
comparar `sha256sum scripts/deploy-artifact.py scripts/atom-publish.py` (en el
checkout de la revisión) con
`sha256sum /usr/local/lib/atom-publish/deploy-artifact.py /usr/local/sbin/atom-publish`
(en Archive).

**Detener la publicación y pedir revisión** (no lanzar `deploy`, o no relanzarlo) si:

- La revisión no es la punta de `main` o su CI de `push` no está en verde.
- La revisión cambia `upstream/atom`, `config/atom/`, configuración de
  instalación o servicios, o requiere migraciones (STOP de
  [deploy-artifact.md](deploy-artifact.md#contrato-de-actualización)).
- La revisión cambia `scripts/deploy-artifact.py` o `scripts/atom-publish.py` y no
  se han reinstalado en Archive.
- El `check` falla o informa desviaciones de permisos inesperadas.
- Algún servicio no está `active` antes de publicar, o el worker acumula reinicios.
- Hay otra operación en curso sobre Archive (importación, mantenimiento,
  RECONCILE).
- Un `deploy` falla: no repetirlo a ciegas; revisar el log del run y los del
  servidor según [Resultado y fallos](deploy-artifact.md#resultado-y-fallos).
