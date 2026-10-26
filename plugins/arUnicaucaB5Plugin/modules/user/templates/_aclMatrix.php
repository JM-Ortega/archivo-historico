<?php
  // Presentación de la matriz de permisos registrados (solo lectura). No calcula permisos efectivos.
  $rows = $sf_data->getRaw('rows');

  // Un grupo por columna (los ids de grupo vienen de la acción) y, al final, la configuración individual.
  $columns = [];
  foreach ($sf_data->getRaw('columnIds') as $columnId) {
      if (null !== $group = QubitAclGroup::getById($columnId)) {
          $columns[] = ['id' => $columnId, 'label' => $group->__toString(), 'individual' => false];
      } elseif ($resource->username == $columnId) {
          $columns[] = ['id' => $columnId, 'label' => $resource->username, 'individual' => true];
      }
  }

  $badge = function ($permission) {
      if (null === $permission) {
          return '<span class="ah-user-badge ah-user-badge--unset">'.__('Sin configuración').'</span>';
      }

      switch ($permission->grantDeny) {
          case 1:
              $key = 'grant';
              $label = __('Conceder');

              break;

          case 0:
              $key = 'deny';
              $label = __('Denegar');

              break;

          default:
              $key = 'inherit';
              $label = __('Heredar');
      }

      $html = '<span class="ah-user-badge ah-user-badge--'.$key.'">'.$label.'</span>';

      if ('translate' == $permission->action && null !== $languages = $permission->getConstants(['name' => 'languages'])) {
          $html .= ' <span class="ah-user-badge-note">'.esc_entities(implode(', ', $languages)).'</span>';
      }

      return $html;
  };
?>

<?php if (0 < count($rows)) { ?>
  <ul class="ah-user-legend" aria-label="<?php echo __('Leyenda'); ?>">
    <li><span class="ah-user-badge ah-user-badge--grant"><?php echo __('Conceder'); ?></span></li>
    <li><span class="ah-user-badge ah-user-badge--deny"><?php echo __('Denegar'); ?></span></li>
    <li><span class="ah-user-badge ah-user-badge--inherit"><?php echo __('Heredar'); ?></span></li>
    <li><span class="ah-user-badge ah-user-badge--unset"><?php echo __('Sin configuración'); ?></span></li>
  </ul>

  <div class="ah-user-matrix-wrap">
    <table id="userPermissions" class="ah-user-matrix">
      <thead>
        <tr>
          <th scope="col"><?php echo __('Operación'); ?></th>
          <?php foreach ($columns as $column) { ?>
            <th scope="col" class="ah-user-matrix-col">
              <?php echo $column['individual']
                  ? __('Configuración individual')
                  : __('Grupo: %1%', ['%1%' => esc_entities($column['label'])]); ?>
            </th>
          <?php } ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row) { ?>
          <tr class="ah-user-matrix-section">
            <th scope="colgroup" colspan="<?php echo count($columns) + 1; ?>"><?php echo $row['heading']; ?></th>
          </tr>
          <?php foreach ($row['actions'] as $action) { ?>
            <tr>
              <th scope="row"><?php echo $action['label']; ?></th>
              <?php foreach ($columns as $column) { ?>
                <td data-label="<?php echo $column['individual']
                    ? esc_specialchars(__('Configuración individual'))
                    : esc_specialchars(__('Grupo: %1%', ['%1%' => $column['label']])); ?>">
                  <?php echo $badge($action['cells'][$column['id']] ?? null); ?>
                </td>
              <?php } ?>
            </tr>
          <?php } ?>
        <?php } ?>
      </tbody>
    </table>
  </div>
<?php } else { ?>
  <p class="ah-user-note"><?php echo __('No hay configuraciones registradas en esta categoría.'); ?></p>
<?php } ?>
