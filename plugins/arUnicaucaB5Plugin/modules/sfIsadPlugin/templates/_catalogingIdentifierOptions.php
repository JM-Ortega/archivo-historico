<?php
/*
 * Copia de informationobject/_identifierOptions.php (AtoM 2.10.2) para el formulario de catalogación: mismo markup y mismo
 * contrato de POST (alternativeIdentifiers[i][id|label|identifier], usingMask). Única diferencia: si el formulario vuelve por
 * un error de validación, las filas se reconstruyen desde lo enviado (arUnicaucaCatalogingResubmit) en lugar de desde la BD.
 * El original lo comparten otros estándares de descripción, por eso no se sobrescribe.
 */
$altRows = [];
if (arUnicaucaCatalogingResubmit::active()) {
    foreach (arUnicaucaCatalogingResubmit::rows('alternativeIdentifiers', ['label', 'identifier']) as $posted) {
        $altRows[] = ['id' => $posted['id'], 'label' => $posted['label'], 'identifier' => $posted['identifier']];
    }
} else {
    foreach ($alternativeIdentifiers as $item) {
        $altRows[] = ['id' => $item->id, 'label' => $item->name, 'identifier' => $item->getValue(['sourceCulture' => true])];
    }
}
?>
<?php if (isset($mask)) { ?>
  <input
    name="usingMask"
    id="using-identifier-mask"
    type="hidden"
    value="<?php echo $mask; ?>">
<?php } ?>

<div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
  <?php if (empty($hideAltIdButton)) { ?>
    <button
      class="btn atom-btn-white text-wrap<?php echo 0 < count($altRows) ? '' : ' collapsed'; ?>"
      type="button"
      data-bs-toggle="collapse"
      data-bs-target="#alternative-identifiers-table"
      aria-expanded="<?php echo 0 < count($altRows) ? 'true' : 'false'; ?>"
      aria-controls="alternative-identifiers-table">
      <i class="fas fa-plus me-1" aria-hidden="true"></i>
      <?php echo __('Add alternative identifier(s)'); ?>
    </button>
  <?php } ?>

  <?php if (empty($hideGenerateButton)) { ?>
    <button
      class="btn atom-btn-white text-wrap"
      id="generate-identifier"
      type="button"
      data-generate-identifier-url="<?php echo url_for([
          'module' => 'informationobject',
          'action' => 'generateIdentifier',
      ]); ?>">
      <i class="fas fa-cog me-1" aria-hidden="true"></i>
      <?php echo __('Generate identifier'); ?>
    </button>
  <?php } ?>
</div>

<?php if (empty($hideAltIdButton)) { ?>
  <div
    id="alternative-identifiers-table"
    class="collapse<?php echo 0 < count($altRows) ? ' show' : ''; ?>">
    <h3 class="fs-6 mb-2">
      <?php echo __('Alternative identifier(s)'); ?>
    </h3>

    <div class="table-responsive mb-2">
      <table class="table table-bordered mb-0 multi-row">
        <thead class="table-light">
          <tr>
            <th id="alt-identifiers-label-head" class="w-50">
              <?php echo __('Label'); ?>
            </th>
            <th id="alt-identifiers-identifier-head" class="w-50"> 
              <?php echo __('Identifier'); ?>
            </th>
            <th>
              <span class="visually-hidden"><?php echo __('Delete'); ?></span>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php $i = 0;
          foreach ($altRows as $item) { ?>
            <?php $form->getWidgetSchema()->setNameFormat("alternativeIdentifiers[{$i}][%s]");
            ++$i; ?>

            <tr<?php echo '' !== (string) $item['id'] ? ' class="related_obj_'.(int) $item['id'].'"' : ''; ?>>
              <td>
                <?php if ('' !== (string) $item['id']) { ?>
                  <input
                    type="hidden"
                    name="<?php echo $form->getWidgetSchema()->generateName('id'); ?>"
                    value="<?php echo (int) $item['id']; ?>">
                <?php } ?>
                <?php $form->setDefault('label', $item['label']); ?>
                <?php echo render_field($form->label, null, [
                    'aria-labelledby' => 'alt-identifiers-label-head',
                    'aria-describedby' => 'alt-identifiers-table-help',
                    'onlyInputs' => true,
                ]); ?>
              </td>
              <td>
                <?php $form->setDefault('identifier', $item['identifier']); ?>
                <?php echo render_field($form->identifier, null, [
                    'aria-labelledby' => 'alt-identifiers-identifier-head',
                    'aria-describedby' => 'alt-identifiers-table-help',
                    'onlyInputs' => true,
                ]); ?>
              </td>
              <td>
                <button type="button" class="multi-row-delete btn atom-btn-white">
                  <i class="fas fa-times" aria-hidden="true"></i>
                  <span class="visually-hidden"><?php echo __('Delete row'); ?></span>
                </button>
              </td>
            </tr>
          <?php } ?>

          <?php $form->getWidgetSchema()->setNameFormat("alternativeIdentifiers[{$i}][%s]"); ?>

          <tr>
            <td>
              <?php $form->setDefault('label', ''); ?>
              <?php echo render_field($form->label, null, [
                  'aria-labelledby' => 'alt-identifiers-label-head',
                  'aria-describedby' => 'alt-identifiers-table-help',
                  'onlyInputs' => true,
              ]); ?>
            </td>
            <td>
              <?php $form->setDefault('identifier', ''); ?>
              <?php echo render_field($form->identifier, null, [
                  'aria-labelledby' => 'alt-identifiers-identifier-head',
                  'aria-describedby' => 'alt-identifiers-table-help',
                  'onlyInputs' => true,
              ]); ?>
            </td>
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
            <td colspan="3">
              <button type="button" class="multi-row-add btn atom-btn-white">
                <i class="fas fa-plus me-1" aria-hidden="true"></i>
                <?php echo __('Add new'); ?>
              </button>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>

    <div class="form-text mb-3" id="alt-identifiers-table-help">
      <?php echo __(
          '<strong>Label:</strong> Enter a name for the alternative identifier field that indicates its purpose and usage.<br/><strong>Identifier:</strong> Enter a legacy reference code, alternative identifier, or any other alpha-numeric string associated with the record.'
      ); ?>
    </div>
  </div>
<?php } ?>
