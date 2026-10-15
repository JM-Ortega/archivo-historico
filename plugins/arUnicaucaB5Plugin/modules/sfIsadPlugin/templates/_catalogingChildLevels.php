<?php
/*
 * Copia de informationobject/_childLevels.php (AtoM 2.10.2) para el formulario de catalogación: mismo markup y mismo contrato
 * de POST (updateChildLevels[i][...]). Única diferencia: si el formulario vuelve por un error de validación, las filas escritas
 * se reconstruyen desde lo enviado (arUnicaucaCatalogingResubmit) en lugar de volver vacías. El original lo comparten otros
 * estándares de descripción, por eso no se sobrescribe.
 */
?>
<h3 class="fs-6 mb-2">
  <?php echo __('Add new child levels'); ?>
</h3>

<div class="table-responsive mb-2">
  <table class="table table-bordered mb-0 multi-row">
    <thead class="table-light">
      <tr>
        <th id="child-identifier-head" class="w-20">
          <?php echo __('Identifier'); ?>
        </th>
        <th id="child-level-head" class="w-20">
          <?php echo __('Level'); ?>
        </th>
        <th id="child-title-head" class="w-40">
          <?php echo __('Title'); ?>
        </th>
        <th id="child-date-head" class="w-20">
          <?php echo __('Date'); ?>
        </th>
        <th>
          <span class="visually-hidden"><?php echo __('Delete'); ?></span>
        </th>
      </tr>
    </thead>
    <tbody>
      <?php
          $rows = arUnicaucaCatalogingResubmit::active()
              ? arUnicaucaCatalogingResubmit::rows('updateChildLevels', ['identifier', 'levelOfDescription', 'title', 'date'])
              : [];
          $rows[] = ['identifier' => '', 'levelOfDescription' => '', 'title' => '', 'date' => '', 'startDate' => '', 'endDate' => ''];
          $levels = QubitTerm::getLevelsOfDescription();
      ?>
      <?php foreach ($rows as $i => $row) { ?>
        <?php $e = static fn ($field) => htmlspecialchars($row[$field] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        <tr class="date">
          <td>
            <input
              type="text"
              name="updateChildLevels[<?php echo $i; ?>][identifier]"
              value="<?php echo $e('identifier'); ?>"
              aria-labelledby="child-identifier-head"
              aria-describedby="child-table-help"
              class="form-control">
          </td>
          <td>
            <select
              name="updateChildLevels[<?php echo $i; ?>][levelOfDescription]"
              aria-labelledby="child-level-head"
              aria-describedby="child-table-help"
              class="form-control form-select">
              <option value=""></option>
              <?php foreach ($levels as $item) { ?>
                <option value="<?php echo $item->id; ?>"<?php echo (string) $item->id === $row['levelOfDescription'] ? ' selected' : ''; ?>>
                  <?php echo $item->__toString(); ?>
                </option>
              <?php } ?>
            </select>
          </td>
          <td>
            <input
              type="text"
              name="updateChildLevels[<?php echo $i; ?>][title]"
              value="<?php echo $e('title'); ?>"
              aria-labelledby="child-title-head"
              aria-describedby="child-table-help"
              class="form-control">
          </td>
          <td>
            <input
              type="text"
              name="updateChildLevels[<?php echo $i; ?>][date]"
              id="updateChildLevels_<?php echo $i; ?>_date"
              value="<?php echo $e('date'); ?>"
              aria-labelledby="child-date-head"
              aria-describedby="child-table-help"
              class="form-control">
            <input
              type="hidden"
              name="updateChildLevels[<?php echo $i; ?>][startDate]"
              id="updateChildLevels_<?php echo $i; ?>_startDate"
              value="<?php echo $e('startDate'); ?>">
            <input
              type="hidden"
              name="updateChildLevels[<?php echo $i; ?>][endDate]"
              id="updateChildLevels_<?php echo $i; ?>_endDate"
              value="<?php echo $e('endDate'); ?>">
          </td>
          <td>
            <button type="button" class="multi-row-delete btn atom-btn-white">
              <i class="fas fa-times" aria-hidden="true"></i>
              <span class="visually-hidden"><?php echo __('Delete row'); ?></span>
            </button>
          </td>
        </tr>
      <?php } ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="5">
          <button type="button" class="multi-row-add btn atom-btn-white">
            <i class="fas fa-plus me-1" aria-hidden="true"></i>
            <?php echo __('Add new'); ?>
          </button>
        </td>
      </tr>
    </tfoot>
  </table>
</div>

<?php if (isset($help)) { ?>
  <div class="form-text mb-3" id="child-table-help">
    <?php echo $sf_data->getRaw('help'); ?>
  </div>
<?php } ?>
