# Índice de elementos ocultos

Una fila por cambio, en orden de identificador.

| ID | Fecha | Elemento | Ruta del archivo o ubicación | Tipo |
| --- | --- | --- | --- | --- |
| OCULTO-001 | 13/10/2026 | Caja de búsqueda del encabezado | `plugins/arUnicaucaB5Plugin/templates/_header.php` | Reubicado |
| OCULTO-002 | 25/10/2026 | Menú superior de importar del administrador | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_mainMenu.php` | Oculto |
| OCULTO-018 | 05/11/2026 | Grupo «contribuidor» en el campo Grupos de usuarios | `plugins/arUnicaucaB5Plugin/modules/user/actions/editAction.class.php` | Oculto |
| OCULTO-019 | 06/11/2026 | Grupos distintos de administrador y catalogador en «Grupos asignados» de la ficha del usuario | `plugins/arUnicaucaB5Plugin/modules/user/templates/indexSuccess.mod_standard.php` | Oculto |
| OCULTO-020 | 06/11/2026 | Columnas de grupos distintos de administrador y catalogador en la matriz de permisos del usuario | `plugins/arUnicaucaB5Plugin/modules/user/templates/_aclMatrix.php` | Oculto |
| OCULTO-021 | 06/11/2026 | Rol «Usuario autenticado» en la lista de usuarios | `plugins/arUnicaucaB5Plugin/modules/user/templates/listSuccess.php` | Reemplazado |
| OCULTO-022 | 06/11/2026 | Pestañas Registros de autoridad, Instituciones archivísticas y Taxonomías de los permisos del usuario | `plugins/arUnicaucaB5Plugin/modules/user/templates/_aclMenu.php` | Oculto |
| OCULTO-023 | 06/11/2026 | Opciones Registro de autoridad, Institución archivística, Término y Función del filtro «Tipo» del historial | `plugins/arUnicaucaB5Plugin/modules/search/actions/descriptionUpdatesAction.class.php` | Oculto |
| OCULTO-024 | 06/11/2026 | Panel «Traducir interfaz de usuario» al pie de la página | `plugins/arUnicaucaB5Plugin/templates/_footer.php` | Oculto |

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

### OCULTO-018 - Grupo «contribuidor» en el formulario de usuario

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-018 |
| Tipo y método | Oculto (consulta original comentada y reemplazada por una lista de grupos permitidos) |
| Elemento y dónde se veía | La opción **contribuidor** del campo **Grupos de usuarios**, en Administrador → Usuarios → Crear nuevo usuario / Editar. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/user/actions/editAction.class.php`, `case 'groups'` (override de `upstream/atom/apps/qubit/modules/user/actions/editAction.class.php`) |
| Para qué servía | Asignar a un usuario el rol contribuidor, que crea y edita descripciones pero no publica ni borra. El campo ofrecía todos los grupos con id mayor que 99. |
| Motivo | El archivo solo usa dos roles: administrador (100) y catalogador (101, el grupo «editor» de AtoM renombrado). El grupo sigue en la base de datos; un usuario que ya lo tenga lo pierde si se edita y se guarda. Para revertir, agregar el id a `$visibleGroupIds`. |

### OCULTO-019 - Grupos ajenos en la ficha del usuario

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-019 |
| Tipo y método | Oculto (línea original comentada y lista filtrada por ids permitidos) |
| Elemento y dónde se veía | En la ficha de cada usuario (Administrador → Usuarios → clic en un usuario), apartado **Grupos asignados**: aparecía siempre **autenticado** y, si el usuario los tenía, **contribuidor** u otros grupos. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/user/templates/indexSuccess.mod_standard.php` (override de `upstream/atom/apps/qubit/modules/user/templates/indexSuccess.mod_standard.php`) |
| Para qué servía | Mostrar todos los grupos del usuario. AtoM agrega «autenticado» a todos los usuarios de forma implícita (`QubitUser::getAclGroups`). |
| Motivo | Solo deben verse administrador y catalogador. Si el usuario no tiene ninguno de los dos se muestra «Sin grupos asignados». No cambia permisos. Para revertir, agregar el id a `$visibleGroupIds`. |

### OCULTO-020 - Columnas de grupos ajenos en la matriz de permisos

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-020 |
| Tipo y método | Oculto (bloque original comentado y columnas filtradas por ids permitidos) |
| Elemento y dónde se veía | En la ficha del usuario, pestañas de permisos (descripciones archivísticas, registros de autoridad, instituciones y términos): la columna **Grupo: autenticado** (cuando el usuario no tiene grupos) y las columnas de **contribuidor** u otros grupos. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/user/templates/_aclMatrix.php` (partial del theme que usan `indexInformationObjectAclSuccess.php`, `indexActorAclSuccess.php`, `indexRepositoryAclSuccess.php` e `indexTermAclSuccess.php`) |
| Para qué servía | Ver las configuraciones de permisos registradas para cada grupo del usuario junto a su configuración individual. |
| Motivo | Solo deben verse administrador y catalogador; la columna individual del usuario se conserva. Los permisos de los grupos ocultos siguen aplicando aunque su columna no se vea. Para revertir, agregar el id a `$visibleGroupIds`. |

### OCULTO-021 - Rol «Usuario autenticado» en la lista de usuarios

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-021 |
| Tipo y método | Reemplazado (línea original comentada; texto nuevo) |
| Elemento y dónde se veía | Columna **Rol** de Administrador → Usuarios, para las cuentas que no son administrador ni catalogador. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/user/templates/listSuccess.php` |
| Para qué servía | Indicar que la cuenta solo pertenece al grupo autenticado de AtoM. |
| Motivo | El grupo autenticado no se muestra. Esas cuentas ahora dicen **Sin rol asignado**. |

### OCULTO-022 - Pestañas de permisos sobre autoridades, instituciones y taxonomías

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-022 |
| Tipo y método | Oculto (excluido por condición: lista `$hiddenAclItems`; el código original queda intacto) |
| Elemento y dónde se veía | Pestañas **Registros de autoridad**, **Instituciones archivísticas** y **Taxonomías** de la sección Permisos, en la ficha de cada usuario (Administrador → Usuarios → clic en un usuario) y en sus vistas de permisos. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/user/templates/_aclMenu.php` (partial que usan `indexSuccess.mod_standard.php` y las cuatro vistas `index*AclSuccess.php`) |
| Para qué servía | Consultar los permisos del usuario y de sus grupos sobre registros de autoridad, instituciones archivísticas y términos de taxonomías. |
| Motivo | El archivo no maneja registros de autoridad ni instituciones y no gestiona taxonomías desde la interfaz; solo quedan los permisos sobre descripciones archivísticas. Limitación: las URL `user/indexActorAcl`, `indexRepositoryAcl` e `indexTermAcl` siguen abriendo. Para revertir, quitar el `name` de `$hiddenAclItems`. |

### OCULTO-023 - Tipos ajenos en el filtro del historial

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-023 |
| Tipo y método | Oculto (lista original comentada y reemplazada) |
| Elemento y dónde se veía | Opciones **Registro de autoridad**, **Institución archivística**, **Término** y **Función** del campo **Tipo** en Administrador → Historial → Opciones de filtrado. Solo queda Descripción archivística. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/search/actions/descriptionUpdatesAction.class.php` (override de `upstream/atom/apps/qubit/modules/search/actions/descriptionUpdatesAction.class.php`) |
| Para qué servía | Consultar en el historial los registros de autoridad, las instituciones, los términos y las funciones creados o modificados. |
| Motivo | El archivo no maneja registros de autoridad, instituciones ni funciones, y no gestiona taxonomías desde la interfaz. Limitación: la URL con `className=QubitActor`, `QubitRepository`, `QubitTerm` o `QubitFunctionObject` sigue respondiendo. En el mismo override, el filtro **Institución archivística** arranca en «Archivo Central del Cauca» si existe (no es un ocultamiento). |

### OCULTO-024 - Panel «Traducir interfaz de usuario»

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-024 |
| Tipo y método | Oculto (bloque comentado) |
| Elemento y dónde se veía | La pestaña **Traducir interfaz de usuario** fija al pie de todas las páginas, visible para administradores y traductores. Al abrirla mostraba el panel con las columnas **Texto de la página**, **Fuente** y **Traducción** y el botón **Guardar traducción**. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/templates/_footer.php` (llamada a `get_component('sfTranslatePlugin', 'translate')`, de `upstream/atom/plugins/sfTranslatePlugin`) |
| Para qué servía | Traducir en vivo los textos de la interfaz de AtoM: listaba las cadenas de la página actual (en verde las ya traducidas) y permitía escribir y guardar su traducción al idioma activo. |
| Motivo | Las traducciones guardadas desde ese panel quedan en la instalación, no en el repositorio, y se pueden perder al actualizar AtoM o desplegar en otro servidor. Los textos se traducen en el código del theme. Limitación: solo se oculta el panel; la ruta `sfTranslatePlugin/translate` y el permiso de traducir siguen habilitados. Para revertir, descomentar el bloque. |
