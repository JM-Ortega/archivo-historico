<?php
/*
 * Copia de object/_notes.php (AtoM 2.10.2) para las notas de los campos adicionales del formulario de catalogación (notas de
 * publicación y del archivero): mismo markup, ayuda y comportamiento, y mismo contrato de POST ($arrayName[i][id|type|content]).
 * Única diferencia: si el formulario vuelve por un error de validación, las filas se reconstruyen desde lo enviado
 * (arUnicaucaCatalogingResubmit) en lugar de desde la BD. El original lo comparten otros formularios, por eso no se sobrescribe.
 */
$noteRows = [];
if (arUnicaucaCatalogingResubmit::active()) {
    foreach (arUnicaucaCatalogingResubmit::rows($arrayName, ['content']) as $posted) {
        $noteRows[] = [
            'id' => $posted['id'],
            'content' => $posted['content'],
            'type' => $posted['type'],
            'note' => '' !== $posted['id'] ? QubitNote::getById($posted['id']) : null,
        ];
    }
} else {
    foreach ($notes as $item) {
        $noteRows[] = ['id' => $item->id, 'content' => $item->getContent(), 'type' => $item->typeId, 'note' => $item];
    }
}
?>
<h3 class="fs-6 mb-2">
  <?php echo $tableName; ?>
</h3>

<div class="table-responsive mb-2">
  <table class="table table-bordered mb-0 multi-row">
    <thead class="table-light">
      <tr>
	<?php if ($hiddenType) { ?>
          <th id="<?php echo $arrayName; ?>-content-head" class="w-100">
            <?php echo __('Content'); ?>
          </th>
	<?php } else { ?>
          <th id="<?php echo $arrayName; ?>-content-head" class="w-70">
            <?php echo __('Content'); ?>
          </th>
          <th id="<?php echo $arrayName; ?>-type-head" class="w-30">
            <?php echo __('Type'); ?>
          </th>
        <?php } ?>
        <th>
          <span class="visually-hidden"><?php echo __('Delete'); ?></span>
        </th>
      </tr>
    </thead>
    <tbody>
      <?php $i = 0;
      foreach ($noteRows as $row) { ?>
        <?php $item = $row['note']; ?>
        <?php $form->getWidgetSchema()->setNameFormat($arrayName."[{$i}][%s]");
        ++$i; ?>

        <tr<?php echo '' !== (string) $row['id'] ? ' class="related_obj_'.(int) $row['id'].'"' : ''; ?>>
          <td>
            <?php if ('' !== (string) $row['id']) { ?>
              <input
                type="hidden"
                name="<?php echo $form->getWidgetSchema()->generateName('id'); ?>"
                value="<?php echo (int) $row['id']; ?>">
            <?php } ?>
            <?php if ($hiddenType) { ?>
              <input
                type="hidden"
                name="<?php echo $form->getWidgetSchema()->generateName('type'); ?>"
                value="<?php echo $hiddenTypeId; ?>">
            <?php } ?>
            <?php $form->setDefault('content', $row['content']); ?>
            <?php echo render_field($form->content, $item, [
                'aria-labelledby' => $arrayName.'-content-head',
                'aria-describedby' => $arrayName.'-table-help',
                'onlyInputs' => true,
            ]); ?>
          </td>
          <?php if (!$hiddenType) { ?>
            <td>
              <?php $form->setDefault('type', $row['type']); ?>
              <?php echo render_field($form->type, null, [
                  'aria-labelledby' => $arrayName.'-type-head',
                  'aria-describedby' => $arrayName.'-table-help',
                  'onlyInputs' => true,
              ]); ?>
            </td>
          <?php } ?>
          <td>
            <button type="button" class="multi-row-delete btn atom-btn-white">
              <i class="fas fa-times" aria-hidden="true"></i>
              <span class="visually-hidden"><?php echo __('Delete row'); ?></span>
            </button>
          </td>
        </tr>
      <?php } ?>

      <?php $form->getWidgetSchema()->setNameFormat($arrayName."[{$i}][%s]"); ?>

      <tr>
        <td>
          <?php if ($hiddenType) { ?>
            <input
              type="hidden"
              name="<?php echo $form->getWidgetSchema()->generateName('type'); ?>"
              value="<?php echo $hiddenTypeId; ?>">
          <?php } ?>
          <?php $form->setDefault('content', ''); ?>
          <?php echo render_field($form->content, null, [
              'aria-labelledby' => $arrayName.'-content-head',
              'aria-describedby' => $arrayName.'-table-help',
              'onlyInputs' => true,
          ]); ?>
        </td>
        <?php if (!$hiddenType) { ?>
          <td>
            <?php $form->setDefault('type', ''); ?>
            <?php echo render_field($form->type, null, [
                'aria-labelledby' => $arrayName.'-type-head',
                'aria-describedby' => $arrayName.'-table-help',
                'onlyInputs' => true,
            ]); ?>
          </td>
        <?php } ?>
        <td>
          <button type="button" class="multi-row-delete btn atom-btn-white">
            <i class="fas fa-times" aria-hidden="true"></i>
            <span class="visually-hidden"><?php echo __('Delete row'); ?></span>
          </button>
        </td>
      </tr>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="<?php echo $hiddenType ? '2' : '3'; ?>">
          <button type="button" class="multi-row-add btn atom-btn-white">
            <i class="fas fa-plus me-1" aria-hidden="true"></i>
            <?php echo __('Add new'); ?>
          </button>
        </td>
      </tr>
    </tfoot>
  </table>
</div>

<div class="form-text mb-3" id="<?php echo $arrayName; ?>-table-help">
  <?php echo $help; ?>
</div>
