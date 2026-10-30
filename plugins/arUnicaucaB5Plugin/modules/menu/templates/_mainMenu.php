<?php
// PROYECTO: ítems del menú Administrador que SÍ se muestran. El resto queda
// oculto (no borrado): groups, staticPages, menu, plugins, themes, settings,
// globalReplace, visibleElements. Para mostrar otro, agrega su `name` aquí.
$visibleAdminItems = ['users', 'descriptionUpdates'];
?>
<?php foreach ([$addMenu, $manageMenu, $importMenu, $adminMenu] as $menu) { ?>
  <?php if (
      $menu && ('add' == $menu->getName()
      || 'manage' == $menu->getName())
      || $sf_user->isAdministrator()
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
        <?php foreach ($menu->getChildren() as $child) { ?>
          <?php
            // Menú Administrador: solo se muestran estos ítems.
            // Ocultos a propósito (no borrados): groups, staticPages, menu, plugins,
            // themes, settings, globalReplace, visibleElements.
            // Para volver a mostrar uno, agrega su `name` a este arreglo.
            if ('admin' === $menu->getName() && !in_array($child->name, ['users', 'descriptionUpdates'], true)) {
                continue;
            }
            ?>
            <?php /* OCULTO-005
                SERVÍA PARA: crear un registro de autoridad nuevo desde el botón «Agregar».
                MOTIVO: el archivo no maneja información de registros de autoridad en este prototipo.
                Código original:
                <?php if ($child->checkUserAccess()) { ?>
              */ ?>
            <?php if ($child->checkUserAccess() && 'addActor' != $child->getName()) { ?>
            <li <?php echo isset($child->name) ? 'id="node_'.$child->name.'"' : ''; ?>>
              <?php echo link_to(
                  $child->getLabel(['cultureFallback' => true]),
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