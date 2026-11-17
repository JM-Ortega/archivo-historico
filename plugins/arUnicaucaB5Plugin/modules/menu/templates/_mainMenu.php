<?php /* OCULTO-010
   SERVÍA PARA: acceder desde el engranaje a Grupos, Páginas estáticas, Menús,
   Plugins, Temas, Configuraciones, Reemplazo global y Elementos visibles.
   MOTIVO: el administrador solo gestiona usuarios y consulta el historial.
   Código original: el foreach de $menu->getChildren() sin filtro (sigue
   intacto debajo; solo se le añadió el `continue` de la lista permitida). */
$visibleAdminItems = ['users', 'descriptionUpdates'];

/* OCULTO-026 (KAN-137): menú «Administrar» (en el código `manage`, el de la barra superior).
   SERVÍA PARA: abrir Ingresos (accession/browse), Donadores (donor/browse), Unidad de almacenaje
   (physicalobject/browse), Titulares de derechos (rightsholder/browse) y Taxonomías (taxonomy/browse).
   MOTIVO: el archivo no gestiona esos registros desde la interfaz (propuesta a confirmar en el daily).
   «Proceso» (jobs/browse) se conserva: muestra el avance de las importaciones.
   Código original: el foreach de $menu->getChildren() sin filtro (sigue intacto; solo se añadió el filtro). */
$visibleManageItems = ['jobs'];

// Etiquetas propias del menú Administrador (el resto usa la de AtoM).
// «Actualizaciones de descripción» se muestra como «Historial» para encontrarlo más fácil.
$adminItemLabels = ['descriptionUpdates' => 'Historial'];
?>
<?php foreach ([$addMenu, $manageMenu, $importMenu, $adminMenu] as $menu) { ?>
  <?php if (
      $menu
      && (
          'add' === $menu->getName()
          || 'manage' === $menu->getName()
          || $sf_user->isAdministrator()
      )
  ) { ?>
    <li class="nav-item dropdown d-flex flex-column">
      <a
        class="nav-link dropdown-toggle d-flex align-items-center p-0"
        href="#"
        id="<?php echo $menu->getName(); ?>-menu"
        role="button"
        data-bs-toggle="dropdown"
        aria-expanded="false">
        <i
          class="fas fa-2x fa-fw fa-<?php echo $icons[$menu->getName()]; ?> px-0 px-lg-2 py-2"
          data-bs-toggle="tooltip"
          data-bs-placement="bottom"
          data-bs-custom-class="d-none d-lg-block"
          title="<?php echo $menu->getLabel(['cultureFallback' => true]); ?>"
          aria-hidden="true">
        </i>
        <span class="d-lg-none mx-1" aria-hidden="true">
          <?php echo $menu->getLabel(['cultureFallback' => true]); ?>
        </span>
        <span class="visually-hidden">
          <?php echo $menu->getLabel(['cultureFallback' => true]); ?>
        </span>
      </a>
      <ul class="dropdown-menu dropdown-menu-end mb-2" aria-labelledby="<?php echo $menu->getName(); ?>-menu">
        <li>
          <h6 class="dropdown-header">
            <?php echo $menu->getLabel(['cultureFallback' => true]); ?>
          </h6>
        </li>
        <?php
          $children = $menu->getChildren();
          // Lista de permitidos del menú actual (null = sin filtro: Agregar e Importar quedan como en AtoM).
          $allowedItems = 'admin' === $menu->getName() ? $visibleAdminItems : ('manage' === $menu->getName() ? $visibleManageItems : null);
          if (null !== $allowedItems) {
              // Mismo orden que la lista de permitidos (p. ej. en Administrador: «Usuarios» y debajo «Historial»).
              $children = array_values(array_filter(
                  is_array($children) ? $children : iterator_to_array($children, false),
                  function ($child) use ($allowedItems) {
                      return in_array($child->name, $allowedItems, true);
                  }
              ));
              usort($children, function ($a, $b) use ($allowedItems) {
                  return array_search($a->name, $allowedItems, true)
                      <=> array_search($b->name, $allowedItems, true);
              });
          }
        ?>
        <?php foreach ($children as $child) { ?>
          <?php
            // Menú Administrador: solo se muestran estos ítems.
            // Ocultos a propósito (no borrados), con lo que hacía cada uno (detalle en docs/hidden-elements.md, OCULTO-010):
            //   groups (grupos y permisos), staticPages (páginas de contenido), menu (editar menús),
            //   plugins (activar plugins), themes (cambiar el tema), settings (ajustes globales),
            //   globalReplace (buscar/reemplazar en masa), visibleElements (campos visibles al público).
            // Para volver a mostrar uno, agrega su `name` a este arreglo.
            if ('admin' === $menu->getName() && !in_array($child->name, $visibleAdminItems, true)) {
                continue;
            }
            // Menú Administrar (manage): solo se muestra «Proceso» (OCULTO-026). Ocultos a propósito, no borrados:
            //   accessions (ingresos), donors (donadores), browsePhysicalObjects (unidad de almacenaje),
            //   rightsholders (titulares de derechos) y taxonomies (taxonomías).
            // Para volver a mostrar uno, agrega su `name` a $visibleManageItems.
            if ('manage' === $menu->getName() && !in_array($child->name, $visibleManageItems, true)) {
                continue;
            }
            ?>
            <?php /* OCULTO-005
              SERVÍA PARA: crear un registro de autoridad nuevo desde el botón «Agregar».
              MOTIVO: el archivo no maneja información de registros de autoridad en este prototipo.
              Código original:
              <?php if ($child->checkUserAccess()) { ?>
            */ ?>
            <?php /* OCULTO-028
              SERVÍA PARA: crear un registro de ingreso (donación o transferencia de material) desde el botón «Agregar».
              MOTIVO: el archivo histórico no registra ingresos de material en este sistema.
              Código original: ninguno; el ítem se mostraba a todo usuario con permiso de creación (no había condición que lo excluyera).
            */ ?>
            <?php if ('add' === $menu->getName() && 'addAccessionRecord' === $child->getName()) { continue; } ?>
            <?php /* OCULTO-029
              SERVÍA PARA: crear un término de un vocabulario controlado (materias, lugares, géneros) desde el botón «Agregar».
              MOTIVO: los términos se crean al catalogar o al importar; no se administran por separado.
              Código original: ninguno; el ítem se mostraba a todo usuario con permiso de creación (no había condición que lo excluyera).
            */ ?>
            <?php if ('add' === $menu->getName() && 'addTerm' === $child->getName()) { continue; } ?>
            <?php /* OCULTO-030
              SERVÍA PARA: crear una función (norma ISDF) desde el botón «Agregar».
              MOTIVO: el archivo histórico no maneja funciones; no las usa.
              Código original: ninguno; el ítem se mostraba a todo usuario con permiso de creación (no había condición que lo excluyera).
            */ ?>
            <?php if ('add' === $menu->getName() && 'addFunction' === $child->getName()) { continue; } ?>
            <?php /* OCULTO-012
             SERVÍA PARA: crear una institución archivística nueva desde el botón «Agregar».
             MOTIVO: el archivo es una sola institución y no maneja este módulo; confunde y satura las opciones.
             Código original:
             <?php if ($child->checkUserAccess() && 'addActor' != $child->getName()) { ?>
            */ ?>
            <?php if ($child->checkUserAccess() && 'addActor' != $child->getName() && 'addRepository' != $child->getName()) { ?>
            <li <?php echo isset($child->name) ? 'id="node_'.$child->name.'"' : ''; ?>>
              <?php echo link_to(
                  ('admin' === $menu->getName() && isset($adminItemLabels[$child->name]))
                      ? __($adminItemLabels[$child->name])
                      : $child->getLabel(['cultureFallback' => true]),
                  $child->getPath(['getUrl' => true, 'resolveAlias' => true]),
                  ['class' => 'dropdown-item']
              ); ?>
            </li>
          <?php } ?>
        <?php } ?>
      </ul>
    </li>
  <?php } ?>
<?php } ?>