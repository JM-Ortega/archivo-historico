<?php if ($sf_context->getConfiguration()->isPluginEnabled('arCasPlugin') || $sf_context->getConfiguration()->isPluginEnabled('arOidcPlugin')) { ?>
  <?php include sfConfig::get('sf_app_module_dir').'/user/templates/_showActions.mod_ext_auth.php'; ?>
<?php } else { ?>
  <?php // Mismas acciones y condiciones que upstream (_showActions.mod_standard.php); solo cambian las etiquetas. ?>
  <?php if (QubitAcl::check($resource, ['create', 'update', 'delete', 'list'])) { ?>
    <ul class="actions mb-3 nav gap-2">

      <?php // En el detalle, "Editar" ya está en la cabecera; en las vistas de permisos lleva a su edición nativa. ?>
      <?php if ('index' !== $sf_context->getActionName() && QubitAcl::check($resource, 'update')) { ?>
        <li><?php echo link_to(__('Editar'), [$resource, 'module' => 'user', 'action' => str_replace('index', 'edit', $sf_context->getActionName())], ['class' => 'btn atom-btn-outline-light']); ?></li>
      <?php } ?>

      <?php if ($sf_user->getAttribute('user_id') != $resource->id && 0 == count($resource->notes) && QubitAcl::check($resource, 'delete')) { ?>
        <li><?php echo link_to(__('Eliminar'), [$resource, 'module' => 'user', 'action' => 'delete'], ['class' => 'btn atom-btn-outline-danger']); ?></li>
      <?php } ?>

      <?php if (QubitAcl::check($resource, 'create')) { ?>
        <li><?php echo link_to(__('Crear nuevo usuario'), ['module' => 'user', 'action' => 'add'], ['class' => 'btn atom-btn-outline-light']); ?></li>
      <?php } ?>

      <?php if (QubitAcl::check($resource, 'list')) { ?>
        <li><?php echo link_to(__('Volver a la lista de usuarios'), ['module' => 'user', 'action' => 'list'], ['class' => 'btn atom-btn-outline-light']); ?></li>
      <?php } ?>

    </ul>
  <?php } ?>
<?php } ?>
