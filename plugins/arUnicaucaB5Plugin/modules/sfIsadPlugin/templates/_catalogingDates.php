<?php use_helper('UcHelp'); ?>
<?php
/*
 * Fechas de la descripción en el formulario de catalogación. Mismo contrato de POST que sfIsadPlugin/_event.php
 * (editEvents[i][id|type|date|startDate|endDate], procesado por sfIsadPluginEventComponent) y misma mecánica de filas
 * (multiRow.js): toda fecha existente se reenvía, así que guardar no borra ninguna.
 *
 * Diferencias de presentación:
 *   - La fila muestra siempre y agrupados la fecha o período registrado (expresión textual original), Desde y Hasta (fechas
 *     estructuradas, opcionales, para la búsqueda cronológica). El tipo de fecha (por defecto Creación, como en AtoM) va en
 *     "Más opciones de fecha", una expansión propia de cada fila. Cerrada solo se oculta con `hidden`: el select sigue en el
 *     formulario y se envía siempre, así que el tipo de una fecha existente se conserva tal cual. Si el tipo no es Creación,
 *     la expansión se muestra abierta para que se vea. Comportamiento: js/cataloging-form.js.
 *   - Las filas no llevan la clase `date`, de modo que date.js no rellena inicio/fin a partir de la expresión: las fechas
 *     normalizadas solo se guardan si el catalogador las escribe.
 *   - Si el formulario vuelve por un error de validación, las filas salen de lo enviado (arUnicaucaCatalogingResubmit), no
 *     de la BD: no se pierden fechas nuevas ni cambios en las existentes.
 */

$creationUrl = url_for([QubitTerm::getById(QubitTerm::CREATION_ID), 'module' => 'term']);

// Filas: [eventId, idUrl, date, startDate, endDate, typeUrl, type (término, para mostrar un tipo fuera de las opciones)].
$rows = [];
if (arUnicaucaCatalogingResubmit::active()) {
    foreach (arUnicaucaCatalogingResubmit::rows('editEvents', ['date', 'startDate', 'endDate']) as $posted) {
        $event = arUnicaucaCatalogingResubmit::resource($posted['id']);
        $rows[] = [
            'eventId' => $event instanceof QubitEvent ? $event->id : null,
            'idUrl' => '' !== $posted['id'] ? $posted['id'] : null,
            'date' => $posted['date'],
            'startDate' => $posted['startDate'],
            'endDate' => $posted['endDate'],
            'typeUrl' => '' !== $posted['type'] ? $posted['type'] : $creationUrl,
            'type' => arUnicaucaCatalogingResubmit::resource($posted['type']),
        ];
    }
} else {
    foreach ($resource->getDates() as $item) {
        $rows[] = [
            'eventId' => $item->id,
            'idUrl' => url_for([$item, 'module' => 'event']),
            'date' => $item->getDate(['cultureFallback' => true]),
            'startDate' => Qubit::renderDate($item->startDate),
            'endDate' => Qubit::renderDate($item->endDate),
            'typeUrl' => url_for([$item->type, 'module' => 'term']),
            'type' => $item->type,
        ];
    }
}
$rows[] = ['eventId' => null, 'idUrl' => null, 'date' => '', 'startDate' => '', 'endDate' => '', 'typeUrl' => $creationUrl, 'type' => null];

// Una fila de fecha (la última es la fila vacía que multiRow.js clona).
$renderRow = static function ($form, int $i, array $row, string $creationUrl): string {
    $form->getWidgetSchema()->setNameFormat("editEvents[{$i}][%s]");
    $typeUrl = $row['typeUrl'];
    $open = $typeUrl !== $creationUrl;
    $moreId = "ah-date-more-{$i}";

    ob_start(); ?>
    <tr<?php echo isset($row['eventId']) ? ' class="related_obj_'.$row['eventId'].'"' : ''; ?>>
      <td data-label="<?php echo __('Fecha o período registrado'); ?>">
        <?php if (isset($row['idUrl'])) { ?>
          <input
            type="hidden"
            name="<?php echo $form->getWidgetSchema()->generateName('id'); ?>"
            value="<?php echo htmlspecialchars($row['idUrl'], ENT_QUOTES, 'UTF-8'); ?>">
        <?php } ?>
        <?php $form->setDefault('date', $row['date']); ?>
        <?php echo render_field($form->date, null, [
            'aria-labelledby' => 'ah-dates-label ah-dates-date-head',
            'aria-describedby' => 'ah-dates-tip',
            'onlyInputs' => true,
        ]); ?>
        <button
          type="button"
          class="ah-date-more-toggle"
          aria-expanded="<?php echo $open ? 'true' : 'false'; ?>"
          aria-controls="<?php echo $moreId; ?>">
          <i class="fas fa-chevron-down" aria-hidden="true"></i>
          <?php echo __('Más opciones de fecha'); ?>
        </button>
        <div class="ah-date-more" id="<?php echo $moreId; ?>"<?php echo $open ? '' : ' hidden'; ?>>
          <span class="ah-date-more-label" aria-hidden="true"><?php echo __('Tipo de fecha'); ?></span>
          <?php
              // Como en _event.php: el tipo actual siempre es una opción, aunque no sea Creación ni Acumulación.
              $save = $form->type->choices;
              if (isset($row['type'])) {
                  $form->type->choices += [$typeUrl => $row['type']];
              }
              $form->setDefault('type', $typeUrl);
              echo render_field($form->type, null, [
                  'aria-label' => __('Tipo de fecha'),
                  'onlyInputs' => true,
              ]);
              $form->type->choices = $save;
          ?>
        </div>
      </td>
      <td data-label="<?php echo __('Desde'); ?>">
        <?php $form->setDefault('startDate', $row['startDate']); ?>
        <?php echo render_field($form->startDate, null, [
            'aria-labelledby' => 'ah-dates-label ah-dates-start-head',
            'aria-describedby' => 'ah-dates-tip',
            'onlyInputs' => true,
        ]); ?>
      </td>
      <td data-label="<?php echo __('Hasta'); ?>">
        <?php $form->setDefault('endDate', $row['endDate']); ?>
        <?php echo render_field($form->endDate, null, [
            'aria-labelledby' => 'ah-dates-label ah-dates-end-head',
            'aria-describedby' => 'ah-dates-tip',
            'onlyInputs' => true,
        ]); ?>
      </td>
      <td class="ah-rows-action">
        <button type="button" class="multi-row-delete btn ah-btn-icon">
          <i class="fas fa-times" aria-hidden="true"></i>
          <span class="visually-hidden"><?php echo __('Quitar esta fecha'); ?></span>
        </button>
      </td>
    </tr>
<?php

    return ob_get_clean();
};
?>
<div class="ah-field">
  <h3 class="form-label ah-field-label"><span id="ah-dates-label"<?php echo isset($labelsByProfile) ? ' data-ah-labels="'.htmlspecialchars(json_encode($sf_data->getRaw('labelsByProfile'), JSON_UNESCAPED_UNICODE), ENT_QUOTES).'"' : ''; ?>><?php echo $label ?? __('Fecha'); ?></span><?php echo uc_help(
      'ah-dates-tip',
      __('Fecha'),
      __('Escribe la fecha o el período tal como está registrado. Desde y Hasta son opcionales: facilitan la búsqueda cronológica por período; complétalos solo cuando puedan determinarse con fiabilidad. Formato: AAAA, AAAA-MM o AAAA-MM-DD.')
  ); ?></h3>

  <div class="table-responsive">
    <table class="table mb-0 multi-row ah-rows ah-dates" aria-labelledby="ah-dates-label" data-default-type="<?php echo $creationUrl; ?>">
      <thead>
        <tr>
          <th id="ah-dates-date-head" class="ah-dates-date"><?php echo __('Fecha o período registrado'); ?></th>
          <th id="ah-dates-start-head" class="ah-dates-normalized"><?php echo __('Desde'); ?></th>
          <th id="ah-dates-end-head" class="ah-dates-normalized"><?php echo __('Hasta'); ?></th>
          <th class="ah-rows-action"><span class="visually-hidden"><?php echo __('Quitar'); ?></span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $row) { ?>
          <?php echo $renderRow($form, $i, $row, $creationUrl); ?>
        <?php } ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4">
            <button type="button" class="multi-row-add btn ah-btn-add">
              <i class="fas fa-plus" aria-hidden="true"></i>
              <?php echo __('Agregar otra fecha'); ?>
            </button>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
