<?php
  // Mismos datos que la plantilla nativa; solo cambia la presentación (ver _aclMatrix).
  $acl = $sf_data->getRaw('acl');
  // Operaciones de AtoM (QubitAcl::$ACTIONS) con el vocabulario del Archivo; si falta una, se usa la etiqueta nativa.
  $actionLabels = [
      'read' => __('Consultar'),
      'create' => __('Crear'),
      'update' => __('Actualizar'),
      'delete' => __('Eliminar'),
      'translate' => __('Traducir'),
      'viewDraft' => __('Ver borrador'),
      'publish' => __('Publicar'),
      'readMaster' => __('Acceder al objeto digital maestro'),
      'readReference' => __('Acceder a la copia de referencia'),
      'readThumbnail' => __('Acceder a la miniatura'),
  ];
  $buildActions = function ($actions, $labels) use ($actionLabels) {
      $result = [];
      foreach ($actions as $action => $groupPermission) {
          $result[] = [
              'label' => ('' != $action) ? esc_entities($actionLabels[$action] ?? $labels[$action]) : '<em>'.__('All privileges').'</em>',
              'cells' => $groupPermission,
          ];
      }

      return $result;
  };

  $rows = [];
  foreach ($acl as $objectId => $actions) {
      $heading = (1 < $objectId)
          ? esc_entities(render_title(QubitActor::getById($objectId)))
          : '<em>'.__('All %1%', ['%1%' => lcfirst(sfConfig::get('app_ui_label_actor'))]).'</em>';
      $rows[] = ['heading' => $heading, 'actions' => $buildActions($actions, QubitAcl::$ACTIONS)];
  }
?>
<div class="ah-user-admin">

  <a class="ah-user-back" href="<?php echo url_for([$resource, 'module' => 'user']); ?>">
    <i class="fas fa-chevron-left" aria-hidden="true"></i>
    <?php echo __('Volver al detalle del usuario'); ?>
  </a>

  <header class="ah-user-page-head">
    <p class="ah-user-eyebrow"><?php echo __('Administración del sistema'); ?></p>
    <h1><?php echo __('Permisos de %1%', ['%1%' => render_title($resource)]); ?></h1>
    <p class="ah-user-lead">
      <?php echo __('Configuraciones registradas para los grupos del usuario y para su cuenta individual. No representan un cálculo de permisos efectivos.'); ?>
    </p>
  </header>

  <div class="ah-user-panel"><div class="ah-user-panel-body">
    <?php echo get_component('user', 'aclMenu'); ?>
    <?php echo get_partial('user/aclMatrix', ['rows' => $rows, 'columnIds' => $sf_data->getRaw('userGroups'), 'resource' => $resource]); ?>
  </div></div>

  <?php echo get_partial('showActions', ['resource' => $resource]); ?>
</div>
