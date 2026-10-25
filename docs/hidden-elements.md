# Índice de elementos ocultos

Una fila por cambio, en orden de identificador.

| ID | Fecha | Elemento | Ruta del archivo o ubicación | Tipo |
| --- | --- | --- | --- | --- |
| OCULTO-001 | 13/10/2026 | Caja de búsqueda del encabezado | `plugins/arUnicaucaB5Plugin/templates/_header.php` | Reubicado |
| OCULTO-002 | 25/10/2026 | Botón de importar del administrador | `plugins/arUnicaucaB5Plugin/modules/informationobject/templates/_actionIcons.php` | Oculto |

## Fichas de detalle

Una ficha por cada fila del índice.

### OCULTO-002 - Botón de importar del administrador

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-002 |
| Tipo y método | Oculto (bloque comentado) |
| Elemento y dónde se veía | Accesos administrativos de importación XML y CSV en la sección **Import** del panel lateral de las fichas de descripción. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/informationobject/templates/_actionIcons.php` (override de `upstream/atom/apps/qubit/modules/informationobject/templates/_actionIcons.php`) |
| Para qué servía | Permitía a los administradores iniciar importaciones de registros XML o CSV desde una ficha de descripción. |
| Motivo | Se deshabilitan los accesos de importación del administrador en la interfaz para evitar que esta operación esté disponible desde el panel. El bloque original queda comentado para conservar la referencia y facilitar una futura reactivación. |
