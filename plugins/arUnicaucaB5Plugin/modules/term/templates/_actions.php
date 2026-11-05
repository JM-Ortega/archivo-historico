<?php
/*
 * Override de upstream/atom/apps/qubit/modules/term/templates/_actions.php (AtoM 2.10.2): acciones al pie de la ficha de
 * un término (Editar, Borrar, Agregar nuevo). Único cambio: OCULTO-017.
 */
$isPlace = isset($resource->taxonomy) && QubitTaxonomy::PLACE_ID == $resource->taxonomy->id;
?>
<?php if (QubitAcl::check($resource->taxonomy, 'createTerm') || (QubitAcl::check($resource, ['update', 'delete', 'translate']) && !QubitTerm::isProtected($resource->id))) { ?>
  <ul class="actions mb-3 nav gap-2">

    <?php if ((QubitAcl::check($resource, 'update') || QubitAcl::check($resource, 'translate')) && !QubitTerm::isProtected($resource->id)) { ?>
      <li><?php echo link_to(__('Edit'), [$resource, 'module' => 'term', 'action' => 'edit'], ['class' => 'btn atom-btn-outline-light']); ?></li>
    <?php } ?>

    <?php if (QubitAcl::check($resource, 'delete') && !QubitTerm::isProtected($resource->id)) { ?>
      <li><?php echo link_to(__('Delete'), [$resource, 'module' => 'term', 'action' => 'delete'], ['class' => 'btn atom-btn-outline-danger']); ?></li>
    <?php } ?>

    <?php /* OCULTO-017
       SERVÍA PARA: crear un lugar nuevo (hijo del actual) desde la ficha de un lugar.
       MOTIVO: fuera de alcance; el prototipo no gestiona el vocabulario de lugares desde la interfaz.
       Se oculta solo en la taxonomía Lugares ($isPlace); materias y géneros conservan el botón.
       Código original:
    <?php if (QubitAcl::check($resource->taxonomy, 'createTerm')) { ?>
      <li><?php echo link_to(__('Add new'), ['module' => 'term', 'action' => 'add', 'parent' => url_for([$resource, 'module' => 'term']), 'taxonomy' => url_for([$resource->taxonomy, 'module' => 'taxonomy'])], ['class' => 'btn atom-btn-outline-light']); ?></li>
    <?php } ?>
    */ ?>
    <?php if (!$isPlace && QubitAcl::check($resource->taxonomy, 'createTerm')) { ?>
      <li><?php echo link_to(__('Add new'), ['module' => 'term', 'action' => 'add', 'parent' => url_for([$resource, 'module' => 'term']), 'taxonomy' => url_for([$resource->taxonomy, 'module' => 'taxonomy'])], ['class' => 'btn atom-btn-outline-light']); ?></li>
    <?php } ?>

  </ul>
<?php } ?>
