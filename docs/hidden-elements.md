# Índice de elementos ocultos

Una fila por cambio, en orden de identificador.

| ID | Fecha | Elemento | Ruta del archivo o ubicación | Tipo |
| --- | --- | --- | --- | --- |
| OCULTO-001 | 13/10/2026 | Caja de búsqueda del encabezado | `plugins/arUnicaucaB5Plugin/templates/_header.php` | Reubicado |
| OCULTO-002 | 25/10/2026 | Menú superior de importar del administrador | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_mainMenu.php` | Oculto |
| OCULTO-003 | 03/11/2026 | Botón «Más» de acciones de la descripción archivística | `plugins/arUnicaucaB5Plugin/modules/informationobject/templates/_actions.php` | Oculto |
| OCULTO-004 | 05/11/2026 | Enlace de documentación oficial de AtoM | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_quickLinksMenu.php` | Oculto |
| OCULTO-005 | 05/11/2026 | Botón «Navegar» y navegación principal del encabezado | `plugins/arUnicaucaB5Plugin/templates/_header.php` | Oculto |
| OCULTO-006 | 06/11/2026 | Botón «Duplicar» de la ficha archivística | `plugins/arUnicaucaB5Plugin/modules/informationobject/templates/_actions.php` | Oculto |
| OCULTO-007 | 06/11/2026 | Panel lateral de opciones de la ficha archivística | `plugins/arUnicaucaB5Plugin/scss/_description.scss` | Oculto (opciones seleccionadas reubicadas) |

## Fichas de detalle

Una ficha por cada fila del índice.

### OCULTO-002 - Menú superior de importar del administrador

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-002 |
| Tipo y método | Oculto (bloque comentado) |
| Elemento y dónde se veía | El tercer icono de la barra superior del sistema, el menú desplegable **Import**, visible para administradores. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_mainMenu.php` (override de `upstream/atom/apps/qubit/modules/menu/templates/_mainMenu.php`) |
| Para qué servía | Permitía a los administradores abrir las acciones de importación de registros XML y CSV. El enlace del botón usa `#` porque abre un menú desplegable, no porque sea un enlace a una página. |
| Motivo | Se oculta el menú superior de importación para que esas operaciones no estén disponibles desde la interfaz. Se conserva la barra con los menús de añadir, gestionar y administración. |

### OCULTO-003 - Botón «Más» de acciones de la descripción archivística

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-003 |
| Tipo y método | Oculto (bloque comentado) |
| Elemento y dónde se veía | Botón «Más» de la barra de acciones al pie de una descripción archivística. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/informationobject/templates/_actions.php` (override de `upstream/atom/apps/qubit/modules/informationobject/templates/_actions.php`) |
| Para qué servía | Abría acciones avanzadas como renombrar, publicar, gestionar almacenamiento físico, objetos digitales, derechos e historial de modificaciones. |
| Motivo | Se ocultan las acciones avanzadas de la interfaz pública, conservando Editar, Borrar, Agregar nuevo y Mover según los permisos del usuario. «Actualizar estado de publicación» y «EAD 2002 XML» se muestran ahora en el panel principal de acciones cuando corresponde. |

### OCULTO-004 - Enlace de documentación oficial de AtoM

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-004 |
| Tipo y método | Oculto (condición de exclusión) |
| Elemento y dónde se veía | Enlace **Documentation** del menú desplegable **Enlaces rápidos**. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_quickLinksMenu.php` |
| Para qué servía | Abría la documentación oficial de AtoM en `docs.accesstomemory.org`. |
| Motivo | Se oculta porque el archivo utiliza su propia información contextual y no requiere enlazar a la documentación externa en inglés. |

### OCULTO-005 - Botón «Navegar» y navegación principal del encabezado

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-005 |
| Tipo y método | Oculto (componentes no renderizados) |
| Elemento y dónde se veía | Botón **Navegar**, menú principal de navegación y botón móvil para abrirlo, en el encabezado global. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/templates/_header.php` |
| Para qué servía | Permitía abrir la navegación principal, explorar las secciones del sistema y acceder al menú Navegar en pantallas pequeñas. |
| Motivo | Se simplifica el encabezado para mostrar únicamente Portapapeles, Idioma, Enlaces rápidos y el control de sesión, manteniendo las utilidades solicitadas para todos los roles. |

### OCULTO-006 - Botón «Duplicar» de la ficha archivística

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-006 |
| Tipo y método | Oculto (elemento no renderizado) |
| Elemento y dónde se veía | Botón **Duplicar** del panel de acciones situado al final de la ficha archivística. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/informationobject/templates/_actions.php` |
| Para qué servía | Permitía crear una nueva descripción archivística copiando la descripción actual. |
| Motivo | Se oculta temporalmente para evitar duplicaciones desde la interfaz pública y liberar espacio en el panel de acciones. |

### OCULTO-007 - Panel lateral de opciones de la ficha archivística

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-007 |
| Tipo y método | Oculto (regla CSS) |
| Elemento y dónde se veía | Panel derecho de la ficha archivística, con opciones de exploración, importación y exportación. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/scss/_description.scss` y `plugins/arUnicaucaB5Plugin/modules/sfIsadPlugin/templates/indexSuccess.php` |
| Para qué servía | Reunía acciones como informes, inventario, navegación como lista, importación XML/CSV y exportaciones, incluida **EAD 2002 XML**. |
| Motivo | Se oculta el panel lateral para simplificar la ficha; **Añadir al portapapeles** y **EAD 2002 XML** se reubican temporalmente en el panel principal de acciones. |
