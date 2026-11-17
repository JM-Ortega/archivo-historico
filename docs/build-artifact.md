# Construcción del artefacto de código para AtoM 2.10.2

El constructor genera un paquete local a partir de una revisión Git. Únicamente
construye y verifica el artefacto; no lo instala ni despliega.

## Requisitos y uso

Requiere Git, Python >= 3.12, Node **22.23.3**, npm **10.9.9** y acceso al registro
npm. El submódulo debe estar inicializado y contener el commit de AtoM 2.10.2
`02a70b8a4b23a805256abd0a14cd0f93e311a581`. No requiere Docker, PHP ni servicios.

Desde la raíz del repositorio, con esas herramientas en PATH:

```bash
python3 scripts/build-artifact.py HEAD
```

Acepta un SHA o una referencia Git como argumento; por defecto usa `HEAD`.
La referencia se resuelve una sola vez al SHA completo. La revisión debe fijar el
submódulo al commit de AtoM indicado. Salida por defecto:
`tmp/artifacts/archivo-historico-<SHA>.tar.gz` (ignorada por Git).
`--output /ruta/directorio` selecciona otro directorio. Se rechaza sobrescribir un
paquete existente. Los cambios del checkout, incluso los versionados, no se incluyen:
las fuentes deben pertenecer a la revisión indicada. El SHA identifica las fuentes
del producto; no identifica necesariamente la versión del constructor ejecutado.

## Proceso de construcción

Se extraen mediante `git archive` el upstream fijado y el plugin completo de la
revisión. Se instalan las dependencias del lockfile upstream con
`npm ci --include=dev --ignore-scripts --no-audit --no-fund`, sin scripts de
instalación ni descargas de Cypress. Se eliminan las salidas previas y se ejecuta
`NODE_ENV=production npm run build`, con el Webpack upstream sin modificaciones.
Todo sucede en un directorio temporal que se elimina al salir.

No se usan `node_modules`, `dist`, plantillas generadas ni archivos ignorados locales.
Las dependencias se fijan mediante el lockfile y sus integridades; Node y npm se
verifican por versión. El tar usa orden, fechas, permisos, propietario y cabecera
gzip estables. No se garantiza identidad binaria entre sistemas operativos o
arquitecturas diferentes.

## Contenido y límites

Las rutas del código empaquetado son relativas a la raíz de AtoM:

| Ruta | Contenido y límite de la superficie |
| --- | --- |
| `plugins/arUnicaucaB5Plugin/` | Plugin completo, incluidas fuentes, herramientas, pruebas, licencias, recursos directos y `_layout_start.php` generado |
| `dist/` | Directorio completo de Webpack: bundles del plugin, Dominion y vendor, fuentes, iconos y mapas |
| `plugins/arDominionB5Plugin/templates/_layout_start.php` | Únicamente este archivo generado, no el directorio Dominion |
| `config/unavailableB5.php` | Únicamente este archivo generado, no el directorio `config/` |
| `config/error/errorB5.html.php` | Únicamente este archivo generado, no el directorio `config/error/` |
| `manifest.json` | Metadatos del paquete, ajenos al runtime de AtoM |

El manifiesto declara formato 1, commits del proyecto y upstream, versiones de
Node/npm, superficies (`directory` / `file`) y SHA-256 de cada archivo del payload.
No se incluye el resto del repositorio ni del upstream. Los directorios declarados
representan superficies completas; los archivos declarados no convierten sus
carpetas contenedoras en superficies administradas.

El paquete excluye BD, uploads, downloads, credenciales, configuración del entorno,
logs y cachés. El constructor no ejecuta Symfony, reconciliadores, migraciones,
instalación de AtoM ni activación del plugin.

## Verificaciones y fallos

Antes de producir el archivo final se comprueban: plugin completo e idéntico a Git,
ausencia de enlaces y archivos fuera de las superficies, cuatro plantillas no
vacías, referencias JS de vendor y JS/CSS del theme en los layouts, CSS de Dominion
en las páginas de error y existencia no vacía de todos esos bundles. Se extrae el
tar temporal y se repiten las verificaciones y los hashes.

El archivo final aparece solo si todo termina correctamente. Un error de
dependencias, compilación o integridad termina con estado distinto de cero.
Los temporales `.partial` se eliminan en los fallos controlados. La salida incluye
la ruta del paquete, su tamaño, el número de archivos del payload y su SHA-256.
Estas comprobaciones validan el artefacto; no validan HTTP ni el comportamiento
funcional del plugin sobre los datos de AtoM.
