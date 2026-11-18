# Actualización de código de una instalación nativa de AtoM

`scripts/deploy-artifact.py` aplica el paquete generado por el
[constructor de artefactos](build-artifact.md) a una instalación existente de
AtoM 2.10.2. No instala AtoM, activa plugins, configura el servidor, reconcilia
ajustes ni ejecuta migraciones. La instalación y activación iniciales deben estar
resueltas antes de usar este procedimiento.

## Contrato de actualización

| Operación | Qué hace | Cuándo y cómo |
| --- | --- | --- |
| DEPLOY | Este script: reemplaza solo las [superficies del paquete](build-artifact.md#contenido-y-límites) y converge permisos | Publicación ordinaria de código y assets del plugin |
| INSTALL | `tools:install` de AtoM: BD inicial y ficheros de configuración del entorno | Una vez, como `root`, antes del primer DEPLOY |
| RECONCILE | `config/atom/reconcile*.{sh,php}`: plugins habilitados, ajustes, términos, Privacidad, «Acerca de» y nombre del grupo, en la BD | Fuera del DEPLOY, explícito, como usuario de runtime |
| UPGRADE | Otra versión de AtoM, cambio de esquema o migración de datos | No soportado por este procedimiento |

El DEPLOY conserva la BD, `uploads/`, `downloads/`, la configuración del entorno y
el contenido de otros plugins (véase [Aplicación](#aplicación)). Rechaza paquetes de
otro commit de AtoM e instalaciones que no sean 2.10.2, pero no inspecciona la BD.

**STOP.** No es un DEPLOY ordinario, y debe planificarse aparte, si la revisión
cambia el submódulo `upstream/atom`, requiere otro esquema o migraciones, toca la
configuración de instalación (`config/config.php`, `propel.ini`, `settings.yml`,
servicios) o cambia el estado deseado de `config/atom/` (el paquete no lo incluye).

Advertencias de RECONCILE conocidas:

- Privacidad y «Acerca de» se sobrescriben en `es` y `en` desde
  `config/atom/content/*.md`; las ediciones hechas en la interfaz se pierden. Esos
  ficheros no viajan en el paquete: en la instalación nativa deben proporcionarse
  (`RECONCILE_PRIVACY_FILE`, `RECONCILE_ABOUT_FILE`).
- Los [términos PROJECT-MANAGED](dev-runbook.md#términos-project-managed-de-taxonomías)
  se identifican por `code`. Un homónimo ajeno o un término propio alterado es STOP
  (78) sin escribir; borrar uno no se recupera recreándolo (los eventos de un tipo
  borrado se eliminan en cascada).

## Requisitos

En el servidor: Linux con systemd, Python >= 3.12, PHP 8.3 con las dependencias de
AtoM, `rsync`, `runuser` y permisos de root mediante `sudo`. La raíz de AtoM debe
ser una ruta absoluta existente, sin enlaces simbólicos en los destinos
administrados ni montajes en el código ni por debajo de `cache/` o `log/`
(véase el [modelo de permisos](#modelo-de-permisos)).

La aplicación debe tener servicios dedicados de web, PHP-FPM y worker. El
procedimiento detiene los tres durante la actualización y los arranca al terminar,
incluso si estaban detenidos previamente. Las unidades deben existir y no estar
enmascaradas. No usar nombres de servicios compartidos con otras aplicaciones.
Por defecto: `nginx`, `php8.3-fpm`, `atom-worker`, usuario `www-data` y ejecutable
PHP `/usr/bin/php8.3`. Se pueden seleccionar con `--web-service`, `--php-service`,
`--worker-service`, `--runtime-user` y `--php`. Todos los consumidores de este árbol
deben estar bajo esos servicios; trabajos independientes deben detenerse por
separado antes de actualizar.

## Transferencia y comprobación

Construir el paquete local y obtener su SHA-256. Confirmar la identidad del
servidor seleccionado antes de transferir o actualizar. Este ejemplo usa el alias
SSH `lab`, `ssh.exe` desde WSL y la raíz `/usr/share/nginx/atom`:

```bash
ARTIFACT='tmp/artifacts/archivo-historico-<SHA_DEL_COMMIT>.tar.gz'
SHA=$(sha256sum "$ARTIFACT" | cut -d ' ' -f1)
ssh.exe lab 'hostname; id'
ssh.exe lab 'umask 077; mkdir -p /tmp/atom-code; cat > /tmp/atom-code/artifact.tar.gz' < "$ARTIFACT"
ssh.exe lab 'umask 077; mkdir -p /tmp/atom-code; cat > /tmp/atom-code/deploy-artifact.py' < scripts/deploy-artifact.py
ssh.exe lab "sudo -n python3 /tmp/atom-code/deploy-artifact.py /tmp/atom-code/artifact.tar.gz --sha256 $SHA --atom-root /usr/share/nginx/atom --check-only"
```

Sustituir el marcador del archivo por el SHA real del commit. `sudo -n` requiere
permisos sin interacción. En clientes con OpenSSH nativo puede usarse `ssh` en
lugar de `ssh.exe`. El SHA esperado debe proceder del build local; verifica
integridad de transferencia y selección del paquete, no constituye una firma.

`--check-only` valida el paquete, la instalación, la sintaxis PHP y el estado de
las unidades de servicio, e informa cuántas desviaciones del
[modelo de permisos](#modelo-de-permisos) corregiría. No detiene servicios ni
escribe código en la instalación.

## Aplicación

Usar el mismo archivo, SHA, raíz y opciones que se comprobaron. Antes de cada
publicación intencional, reiniciar el contador de arranques del worker con la
herramienta nativa de systemd:

```bash
ssh.exe lab 'sudo -n systemctl reset-failed atom-worker'
ssh.exe lab "sudo -n python3 /tmp/atom-code/deploy-artifact.py /tmp/atom-code/artifact.tar.gz --sha256 $SHA --atom-root /usr/share/nginx/atom"
```

`reset-failed` reinicia el contador de límite de arranques incluso si el servicio
está activo. Permite publicaciones consecutivas cuando la unidad configura un
intervalo largo y pocos arranques (`StartLimitIntervalSec` / `StartLimitBurst`),
sin cambiar esos límites. Usar el nombre seleccionado en `--worker-service` si
difiere del ejemplo. Los fallos persistentes del worker requieren diagnóstico
antes de volver a arrancarlo.

El script toma un bloqueo exclusivo sobre la raíz para excluir otra ejecución
del mismo actualizador. Antes de detener servicios, verifica SHA-256, formato y
superficies del manifiesto, hashes de todos los archivos, plantillas y bundles
esenciales y sintaxis PHP. Rechaza rutas que escapen del paquete, entradas
duplicadas, enlaces y archivos fuera de las superficies autorizadas.

Después detiene los tres servicios, aplica el [modelo de permisos](#modelo-de-permisos)
a la instalación y, ya con el código propiedad de `root`, copia conjuntamente:

- `plugins/arUnicaucaB5Plugin/` y `dist/`: sincronización completa con eliminación
  de archivos ausentes en el paquete.
- El layout generado de Dominion y las dos páginas generadas de `config/`:
  actualización de esos tres archivos individuales.

El [contrato del paquete](build-artifact.md#contenido-y-límites) detalla las rutas.
No se sincronizan los directorios contenedores `plugins/`, Dominion ni `config/`.
El manifiesto no se copia al runtime. La copia produce directamente los
propietarios y modos del modelo.

Se comprueba que el código instalado coincide con todos los hashes del paquete,
que desaparecieron los archivos sobrantes en las superficies completas y que la
instalación cumple el modelo. Se ejecuta `php symfony cc` como usuario de runtime
y se arrancan PHP-FPM, worker y web. El reinicio de PHP-FPM renueva OPcache. No se
modifica la BD ni el contenido de uploads o downloads (tampoco sus propietarios
ni modos). La configuración particular del entorno y los archivos de otros plugins
conservan su contenido, pero adoptan los propietarios y modos del modelo. El
contenido de `cache/` se elimina (es desechable).

## Modelo de permisos

El usuario de runtime (`www-data`: PHP-FPM, worker y Nginx) no puede modificar el
código. Cada publicación converge la instalación, con los servicios detenidos:

| Ruta | Propietario | Modo |
|---|---|---|
| Resto del árbol de AtoM (propio, upstream y dependencias) | `root:root` | directorios `0755`; ficheros `0644`, `0755` si tenían algún bit de ejecución |
| `config/config.php`, `apps/qubit/config/settings.yml`, `config/appChallenge.yml` (el runtime los lee; el último contiene el salt del desafío JS) | `root:<grupo de runtime>` | `0640` |
| `config/propel.ini`, `apps/qubit/config/settings.yml.tmpl` (solo INSTALL, como `root`; el `.tmpl` recibe el secreto CSRF de `config/atom/runtime-config.sh`) | `root:root` | `0600` |
| `cache/`, `log/` | runtime | `0750`; se vacía `cache/`; los ficheros de primer nivel de `log/` pasan al runtime con `0640` |
| `uploads/`, `downloads/` | runtime | solo el directorio; su contenido es estado persistente y no se toca |

- Los ficheros con credenciales se identifican por ruta, no por el modo con que
  llegan (`tools:install` puede dejarlos `0644`).
- Cualquier otra entrada cerrada a «otros» (fichero sin lectura, directorio sin
  lectura o recorrido) se rechaza antes de detener servicios: abrirla a todos o
  cerrarla al runtime sería una decisión que el script no toma. Una instalación de
  AtoM no contiene ninguna, salvo que se restringieran ficheros a mano; si aparece,
  hay que clasificarla (o, si no es secreta, dejarla `0644`) antes de publicar.
- También se rechazan antes de detener servicios: `cache/`, `log/`, `uploads/` o
  `downloads/` como enlace o fichero; enlaces del código que salgan de la
  instalación o apunten a esas carpetas; tipos de fichero especiales; ficheros de
  primer nivel de `log/` con más de un enlace duro; y puntos de
  montaje en el código o por debajo de `cache/` o `log/`, según
  `/proc/self/mountinfo`. Las cuatro carpetas pueden ser volúmenes propios, y
  `uploads/` y `downloads/` pueden contener montajes.
- Se eliminan las ACL del código. Un sistema de archivos sin atributos extendidos
  no tiene ACL; cualquier otro error al leerlas aborta.
- Logs: `sfFileLogger` crea cada log con `chmod(0666)` (la `umask` no lo evita).
  `plugins/arUnicaucaB5Plugin/config/factories.yml` fija `file_mode: 0640`, y
  Symfony lo fusiona con `config/factories.yml` de AtoM, por lo que los logs
  recreados (web, CLI y worker) nacen `0640`.

**Operaciones privilegiadas seguras.** En la primera adopción el árbol puede
pertenecer todavía a `www-data`, y `cache/` y `log/` le pertenecen siempre. Un
recorrido por rutas permitiría que un proceso del runtime sustituyera un
directorio intermedio por un enlace simbólico y desviara un `chown`/`chmod` de
`root` fuera de la instalación (comprobado en el laboratorio con una versión
previa de este modelo: en 5 de 5 intentos cambió entre 713 y 1500 de 1500 ficheros
ajenos). Por ello:

- Se recorre de la raíz hacia abajo y cada directorio pasa a `root` sin escritura
  ajena antes de listarse; desde ese momento nadie más puede renombrar ni añadir
  entradas en él.
- Cada entrada se abre relativa al descriptor de su directorio, sin seguir enlaces
  (`O_NOFOLLOW`), se comprueba con `fstat` y se modifica por ese descriptor. Los
  enlaces solo cambian de propietario (`lchown` relativo).
- `cache/` se vacía con `shutil.rmtree` sobre descriptores, que rechaza enlaces
  sustituidos durante el borrado. En `log/` solo se modifican, por descriptor,
  ficheros regulares de primer nivel con un único enlace: un enlace duro a un
  fichero externo nunca se entrega al runtime.
- La copia de rsync ocurre después, sobre directorios que ya son de `root`.

Tras aplicar y copiar, el script audita y, si algo no cumple el modelo, falla
dejando los servicios detenidos (cierre seguro; la operación es idempotente).
`--check-only` informa de las mismas desviaciones sin modificar nada. Aplicarlo
equivale a adoptar el modelo en una instalación existente; no hace falta
reconstruirla. Los ficheros ajenos a las superficies no se eliminan: pasan a `root`.

**Qué garantiza y qué no.** Tras una publicación correcta, el runtime no puede
escribir en el código ni en los ficheros con credenciales y no lee los secretos
que no necesita. La normalización no restaura la integridad del código: el
actualizador solo reemplaza y verifica sus superficies; si otros ficheros del
árbol se modificaron antes de adoptar el modelo, siguen modificados (ahora
propiedad de `root`). Riesgos que permanecen:

- El runtime lee las credenciales de BD de `config/config.php` y escribe en la BD,
  `uploads/` y `downloads/`.
- `cache/` contiene PHP compilado por Symfony y sigue siendo escribible por el
  runtime hasta la siguiente publicación, que lo vacía.
- Un proceso del runtime que siga vivo durante la adopción (por ejemplo, desde su
  `crontab`) y tenga abierto un fichero para escritura conserva ese descriptor. Los
  servicios detenidos no cubren procesos ajenos a ellos: antes de la primera
  adopción, comprobar que `pgrep -u www-data` no devuelve nada.

Operaciones: INSTALL de AtoM, y cualquier tarea que deba escribir en el árbol de
código (por ejemplo `tools:sitemap`, que escribe por defecto en la raíz), se
ejecutan como `root`; la siguiente publicación reconverge permisos. RECONCILE y
las tareas `php symfony` habituales escriben solo BD, caché y logs: ejecutarlas
como el usuario de runtime (`runuser -u www-data -- php8.3 symfony ...`).
`data/` pasa a `root`: solo lo usan los ficheros de bloqueo de `symfony cc` (sin
efecto con `sf_check_lock` desactivado, valor por defecto de AtoM observado en el
laboratorio) y el adaptador cURL de `sfWebBrowserPlugin`; el comprobador de
`tools:install` exige que `data/` sea escribible, por lo que INSTALL se ejecuta
antes de aplicar el modelo.

## Resultado y fallos

El éxito imprime el commit instalado y el SHA-256 del paquete, y confirma que los
tres servicios están activos. Debe comprobarse después la URL local configurada
en Nginx y la carga HTTP de los bundles; el script no ejecuta pruebas funcionales
ni verifica disponibilidad HTTP.

Un error de validación termina antes de detener servicios. Un error desde el
inicio de la parada deja los tres servicios detenidos y devuelve estado distinto
de cero. La copia no es atómica y puede quedar incompleta. Inspeccionar la causa,
corregirla y volver a aplicar el paquete completo con los servicios detenidos.
No hay rollback automático,
reinstalación ni reset implícitos. Se conservan el archivo transferido y el script
para diagnóstico; el directorio temporal de extracción se elimina al salir.

## Recuperación manual de código

Para volver a una revisión anterior compatible, seleccionar su artefacto conservado
o reconstruirlo desde Git con el mismo constructor y sus requisitos:

```bash
python3 scripts/build-artifact.py '<SHA_COMPATIBLE>' --output tmp/artifacts-recovery
```

La revisión debe fijar el mismo AtoM 2.10.2 y su código debe admitir el estado actual
de los datos y la configuración. Elegir el archivo anterior como `ARTIFACT`,
recalcular `SHA` y repetir la transferencia, `--check-only` y aplicación descritos
arriba, incluido `reset-failed` del worker. Comprobar después la URL local y sus
bundles. El script verifica que todas las superficies coinciden con el manifiesto
anterior y elimina en los dos directorios completos los archivos de la revisión
posterior que ya no correspondan.

Esta operación recupera únicamente código; conserva los datos y la configuración
del entorno. Se realiza con el mismo actualizador, también si los servicios quedaron
detenidos tras un fallo. No ejecuta restauración de BD, reconciliación, migraciones
ni rollback automático.

La preservación aquí depende de los límites de escritura del actualizador.
Los efectos del código PHP al ejecutarse requieren sus propias pruebas funcionales.
