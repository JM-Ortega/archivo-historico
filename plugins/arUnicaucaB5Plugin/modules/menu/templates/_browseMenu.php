<div class="dropdown my-2 me-3">
  <button class="btn btn-sm atom-btn-secondary dropdown-toggle" type="button" id="browse-menu" data-bs-toggle="dropdown" aria-expanded="false">
    <?php echo $browseMenu->getLabel(['cultureFallback' => true]); ?>
  </button>
  <ul class="dropdown-menu mt-2" aria-labelledby="browse-menu">
    <li>
      <h6 class="dropdown-header">
        <?php echo $browseMenu->getLabel(['cultureFallback' => true]); ?>
      </h6>
    </li>
    <?php /* OCULTO-004
       SERVÍA PARA: abrir el listado de todos los registros de autoridad (personas, familias y entidades) desde el botón «Navegar».
       MOTIVO: el archivo no maneja información de registros de autoridad en este prototipo.
       Código original:
       <?php if ($child->checkUserAccess()) { ?>
    */ ?>
    <?php foreach ($browseMenu->getChildren() as $child) { ?>
      <?php if ($child->checkUserAccess() && 'browseActors' != $child->getName()) { ?>
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
</div>
