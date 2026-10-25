# Índice de elementos ocultos

Una fila por cambio, en orden de identificador.

| ID | Fecha | Elemento | Ruta del archivo o ubicación | Tipo |
| --- | --- | --- | --- | --- |
| OCULTO-001 | 13/10/2026 | Caja de búsqueda del encabezado | `plugins/arUnicaucaB5Plugin/templates/_header.php` | Reubicado |
| OCULTO-002 | 25/10/2026 | Menú superior de importar del administrador | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_mainMenu.php` | Oculto |

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
