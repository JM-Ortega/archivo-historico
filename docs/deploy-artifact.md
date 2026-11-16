# Actualización de código de una instalación nativa de AtoM

`scripts/deploy-artifact.py` aplica el paquete generado por el
[constructor de artefactos](build-artifact.md) a una instalación existente de
AtoM 2.10.2. No instala AtoM, activa plugins, configura el servidor, reconcilia
ajustes ni ejecuta migraciones. La instalación y activación iniciales deben estar
resueltas antes de usar este procedimiento.

## Requisitos

En el servidor: Linux con systemd, Python >= 3.12, PHP 8.3 con las dependencias de
AtoM, `rsync`, `runuser` y permisos de root mediante `sudo`. La raíz de AtoM debe
ser una ruta absoluta existente, sin enlaces simbólicos en los destinos
administrados ni montajes dentro de los dos directorios reemplazables.

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
las unidades de servicio. No detiene servicios ni escribe código en la instalación.

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

Después detiene los tres servicios y aplica conjuntamente:

- `plugins/arUnicaucaB5Plugin/` y `dist/`: sincronización completa con eliminación
  de archivos ausentes en el paquete.
- El layout generado de Dominion y las dos páginas generadas de `config/`:
  actualización de esos tres archivos individuales.

El [contrato del paquete](build-artifact.md#contenido-y-límites) detalla las rutas.
No se sincronizan los directorios contenedores `plugins/`, Dominion ni `config/`.
El manifiesto no se copia al runtime. El código queda propiedad del usuario de
runtime y su grupo principal, con permisos de lectura y ejecución según el tipo
de archivo, sin escritura para grupo ni otros.

Se comprueba que el código instalado coincide con todos los hashes del paquete
y que desaparecieron los archivos sobrantes en las superficies completas. Se
ejecuta `php symfony cc` como usuario de runtime y se arrancan PHP-FPM, worker y
web. El reinicio de PHP-FPM renueva OPcache. Solo se limpian cachés mediante la
tarea nativa de Symfony; no se modifican BD, uploads, downloads, configuración
particular del entorno ni archivos de otros plugins.

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
