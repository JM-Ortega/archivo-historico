<?php
/*
 * Observaciones: las notas generales ISAD(G) 3.6.1 de la descripción (ObjectNotesComponent, tipo `isadNotes`). Mismo contrato de POST que object/_notes.php ($arrayName[i][id|type|content]) y misma
 * mecánica de filas (multiRow.js): toda nota existente de ese tipo se reenvía con su texto completo. Si el formulario vuelve
 * por un error de validación, las filas salen de lo enviado (arUnicaucaCatalogingResubmit), no de la BD.
 *
 * Las notas de otros tipos (publicación, archivero) tienen su propio componente en los campos adicionales
 * (_catalogingObjectNotes.php).
 *
 * Variables del componente: $form, $notes, $arrayName, $hiddenTypeId, $tableName. Opcionales: $label (por defecto
 * $tableName), $addLabel, $removeLabel.
 */
$label = isset($label) ? $label : $tableName;
$addLabel = isset($addLabel) ? $addLabel : __('Agregar otra nota');
$removeLabel = isset($removeLabel) ? $removeLabel : __('Quitar esta nota');
$labelId = $arrayName.'-label';

// Filas: [id, contenido, nota existente (para mostrar su texto original si la cultura actual no lo tiene)].
$rows = [];
if (arUnicaucaCatalogingResubmit::active()) {
    foreach (arUnicaucaCatalogingResubmit::rows($arrayName, ['content']) as $posted) {
        $rows[] = ['id' => $posted['id'], 'content' => $posted['content'], 'note' => '' !== $posted['id'] ? QubitNote::getById($posted['id']) : null];
    }
} else {
    foreach ($notes as $item) {
        $rows[] = ['id' => $item->id, 'content' => $item->getContent(), 'note' => $item];
    }
}
$rows[] = ['id' => '', 'content' => '', 'note' => null];
?>
<div class="ah-field">
  <h3 class="form-label ah-field-label" id="<?php echo $labelId; ?>"><?php echo $label; ?></h3>

  <table class="table mb-0 multi-row ah-rows ah-notes" aria-labelledby="<?php echo $labelId; ?>">
    <tbody>
      <?php foreach ($rows as $i => $row) { ?>
        <?php $form->getWidgetSchema()->setNameFormat($arrayName."[{$i}][%s]"); ?>
        <tr<?php echo '' !== (string) $row['id'] ? ' class="related_obj_'.(int) $row['id'].'"' : ''; ?>>
          <td>
            <?php if ('' !== (string) $row['id']) { ?>
              <input
                type="hidden"
                name="<?php echo $form->getWidgetSchema()->generateName('id'); ?>"
                value="<?php echo (int) $row['id']; ?>">
            <?php } ?>
            <input
              type="hidden"
              name="<?php echo $form->getWidgetSchema()->generateName('type'); ?>"
              value="<?php echo $hiddenTypeId; ?>">
            <?php $form->setDefault('content', $row['content']); ?>
            <?php echo render_field($form->content, $row['note'], [
                'aria-labelledby' => $labelId,
                'onlyInputs' => true,
                'rows' => 3,
            ]); ?>
          </td>
          <td class="ah-rows-action">
            <button type="button" class="multi-row-delete btn ah-btn-icon">
              <i class="fas fa-times" aria-hidden="true"></i>
              <span class="visually-hidden"><?php echo $removeLabel; ?></span>
            </button>
          </td>
        </tr>
      <?php } ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="2">
          <button type="button" class="multi-row-add btn ah-btn-add">
            <i class="fas fa-plus" aria-hidden="true"></i>
            <?php echo $addLabel; ?>
          </button>
        </td>
      </tr>
    </tfoot>
  </table>
</div>
