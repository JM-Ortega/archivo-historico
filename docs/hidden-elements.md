# Índice de elementos ocultos

Una fila por cambio, en orden de identificador.

| ID | Fecha | Elemento | Ruta del archivo o ubicación | Tipo |
| --- | --- | --- | --- | --- |
| OCULTO-001 | 13/10/2026 | Caja de búsqueda del encabezado | `plugins/arUnicaucaB5Plugin/templates/_header.php` | Reubicado |
| OCULTO-002 | 25/10/2026 | Menú superior de importar del administrador | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_mainMenu.php` | Oculto |
| OCULTO-010 | 02/11/2026 | Opciones del menú «Administrador» (engranaje) distintas de Usuarios e Historial | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_mainMenu.php` | Oculto |
| OCULTO-018 | 05/11/2026 | Grupo «contribuidor» en el campo Grupos de usuarios | `plugins/arUnicaucaB5Plugin/modules/user/actions/editAction.class.php` | Oculto |
| OCULTO-019 | 06/11/2026 | Grupos distintos de administrador y catalogador en «Grupos asignados» de la ficha del usuario | `plugins/arUnicaucaB5Plugin/modules/user/templates/indexSuccess.mod_standard.php` | Oculto |
| OCULTO-020 | 06/11/2026 | Columnas de grupos distintos de administrador y catalogador en la matriz de permisos del usuario | `plugins/arUnicaucaB5Plugin/modules/user/templates/_aclMatrix.php` | Oculto |
| OCULTO-021 | 06/11/2026 | Rol «Usuario autenticado» en la lista de usuarios | `plugins/arUnicaucaB5Plugin/modules/user/templates/listSuccess.php` | Reemplazado |
| OCULTO-022 | 06/11/2026 | Pestañas Registros de autoridad, Instituciones archivísticas y Taxonomías de los permisos del usuario | `plugins/arUnicaucaB5Plugin/modules/user/templates/_aclMenu.php` | Oculto |
| OCULTO-023 | 06/11/2026 | Opciones Registro de autoridad, Institución archivística, Término y Función del filtro «Tipo» del historial | `plugins/arUnicaucaB5Plugin/modules/search/actions/descriptionUpdatesAction.class.php` | Oculto |
| OCULTO-024 | 06/11/2026 | Panel «Traducir interfaz de usuario» al pie de la página | `plugins/arUnicaucaB5Plugin/templates/_footer.php` | Oculto |
| OCULTO-025 | 07/11/2026 | Línea «Parte de…» en cada resultado de búsqueda | `plugins/arUnicaucaB5Plugin/modules/search/templates/_searchResult.php` | Reemplazado |
| OCULTO-026 | 07/11/2026 | Opciones del menú «Administrar» distintas de Proceso (Ingresos, Donadores, Unidad de almacenaje, Titulares de derechos, Taxonomías) | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_mainMenu.php` | Oculto |

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

### OCULTO-010 - Opciones descartadas del menú «Administrador» (engranaje)

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-010 |
| Tipo y método | Oculto (excluido por condición: lista `$visibleAdminItems`; el `foreach` original de `$menu->getChildren()` queda intacto) |
| Elemento y dónde se veía | Ocho de las diez opciones del menú desplegable del engranaje (**Administrador**), visibles para el administrador. Solo quedan **Usuarios** e **Historial** (la opción «Actualizaciones de descripción» con otro nombre). |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_mainMenu.php` (override de `upstream/atom/apps/qubit/modules/menu/templates/_mainMenu.php`). Los nombres internos ocultos están comentados junto a la lista. |
| Para qué servía | Ver el detalle por opción en la tabla siguiente. |
| Motivo | El administrador del archivo solo gestiona usuarios y consulta el historial; el resto es configuración técnica de la instalación que no debe alterarse desde la interfaz. Limitación: las URL de cada opción siguen abriendo si se conocen; solo se quita el enlace del menú. Para revertir una, agregar su nombre interno a `$visibleAdminItems`. |

Detalle de las opciones descartadas:

| Opción (nombre interno) | Ruta | Para qué servía | Por qué se descarta |
| --- | --- | --- | --- |
| Grupos (`groups`) | `aclGroup/list` | Crear y editar grupos de usuarios y sus permisos. | Solo hay dos roles fijos (administrador y catalogador); ver OCULTO-018 a OCULTO-020. |
| Páginas estáticas (`staticPages`) | `staticpage/list` | Editar las páginas de contenido (inicio, «Acerca de», política de privacidad). | Esas páginas las fija el reconcile del proyecto (`reconcile-about.php`, `reconcile-privacy-policy.php`); editarlas a mano se pierde al reconciliar. |
| Menús (`menu`) | `menu/list` | Reordenar, renombrar o añadir entradas de los menús. | El menú lo define el theme del plugin; cambiarlo en la base lo desalinea del código. |
| Plugins (`plugins`) | `sfPluginAdminPlugin/plugins` | Activar o desactivar plugins de AtoM. | Los plugins requeridos los habilita el reconcile (`reconcile-plugins.sh`); apagar uno rompe el theme o la búsqueda. |
| Temas (`themes`) | `sfPluginAdminPlugin/themes` | Elegir el tema visual. | El tema es `arUnicaucaB5Plugin`; cambiarlo elimina la identidad visual. |
| Configuraciones (`settings`) | `settings/global` | Ajustes globales (idioma, correo, reglas de identificadores, seguridad). | Valores como contraseñas fuertes o código de referencia los fija el reconcile; un cambio manual se pisa. |
| Buscar/reemplazar global (`globalReplace`) | `search/globalReplace` | Reemplazar texto en masa en las descripciones. | Operación masiva sin vuelta atrás (solo se deshace con otra); el catálogo se corrige desde el CSV de migración o ficha a ficha. |
| Elementos visibles (`visibleElements`) | `settings/visibleElements` | Mostrar u ocultar campos de la ficha para el público. | La visibilidad de campos se controla en el código del plugin y queda documentada en este archivo. |

Opciones que se conservan: **Usuarios** (`users`) e **Historial** (`descriptionUpdates`). El menú «Administrar» (el de Ingresos, Donadores, Proceso…) es otro y se documenta en OCULTO-026.

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

### OCULTO-025 - «Parte de…» en los resultados de búsqueda

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-025 |
| Tipo y método | Reemplazado (bloque original eliminado del override; en su lugar, la ruta jerárquica) |
| Elemento y dónde se veía | La línea **Parte de «Fondo…»** bajo el código y las fechas de cada resultado de la búsqueda de descripciones archivísticas. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/search/templates/_searchResult.php` (override de `upstream/atom/apps/qubit/modules/search/templates/_searchResult.php`); la ruta la arma `plugins/arUnicaucaB5Plugin/lib/arUnicaucaResultPath.class.php` |
| Para qué servía | Mostrar solo el fondo (nivel superior) al que pertenece la descripción, con enlace. |
| Motivo | La maqueta de Figma «4. Resultados» muestra la ruta jerárquica completa al pie de la ficha (Fondo / Serie / Subserie / Sección). Esa ruta incluye al fondo, así que «Parte de» queda redundante. Limitación: la ruta es texto sin enlaces. Para revertir, restaurar el bloque `isset($doc['partOf'])` del partial original. |

### OCULTO-026 - Opciones descartadas del menú «Administrar» (KAN-137)

| Campo | Detalle |
| --- | --- |
| Identificador | OCULTO-026 |
| Tipo y método | Oculto (excluido por condición: lista `$visibleManageItems`; el `foreach` original de `$menu->getChildren()` queda intacto) |
| Elemento y dónde se veía | Cinco de las seis opciones del menú desplegable **Administrar** de la barra superior (nombre interno `manage`), visibles para Administrador y Catalogador. Solo queda **Proceso**. |
| Ruta o ubicación | `plugins/arUnicaucaB5Plugin/modules/menu/templates/_mainMenu.php` (override de `upstream/atom/apps/qubit/modules/menu/templates/_mainMenu.php`) |
| Para qué servía | Ver la tabla siguiente. |
| Motivo | El archivo no gestiona estos registros desde la interfaz (coherente con OCULTO-022 y OCULTO-023). **Propuesta a confirmar en el daily.** Limitación: las URL siguen abriendo si se conocen (para el Catalogador, `rightsholder/browse` ya responde 403 por permisos). Para revertir una, agregar su nombre interno a `$visibleManageItems`. |

| Opción (nombre interno) | Ruta | Para qué servía | Por qué se descarta |
| --- | --- | --- | --- |
| Ingresos (`accessions`) | `accession/browse` | Registrar y consultar los ingresos de documentos al archivo (transferencias, donaciones). | El Archivo no lleva esa gestión en la aplicación. |
| Donadores (`donors`) | `donor/browse` | Registro de personas o entidades que donan documentos. | Depende de Ingresos; no se usa. |
| Unidad de almacenaje (`browsePhysicalObjects`) | `physicalobject/browse` | Describir y ubicar los contenedores físicos (cajas, estantes). | La ubicación topográfica ya va en la signatura (p. ej. `Ind. C II -24 mn`). |
| Titulares de derechos (`rightsholders`) | `rightsholder/browse` | Gestionar titulares de derechos de autor sobre los documentos. | No se usa; el Catalogador ya no tenía acceso (403). |
| Taxonomías (`taxonomies`) | `taxonomy/browse` | Editar los vocabularios controlados (niveles, tipos de evento, lugares, materias). | Los términos del proyecto los fija el reconcile; no se gestionan taxonomías desde la interfaz. |

Se conserva **Proceso** (`jobs`, `jobs/browse`): muestra el avance de las importaciones.
