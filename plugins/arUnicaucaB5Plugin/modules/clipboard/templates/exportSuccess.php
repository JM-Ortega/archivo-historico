<?php decorate_with('layout_1col.php'); ?>

<?php slot('title'); ?>
  <header class="ah-action-page__header">
    <p class="ah-action-page__kicker"><?php echo __('Gestión de exportaciones'); ?></p>
    <?php if (isset($resource)) { ?>
      <div class="multiline-header d-flex flex-column mb-3">
        <h1 class="mb-0" aria-describedby="heading-label">
          <?php echo $title; ?>
        </h1>
        <span class="small" id="heading-label">
          <?php echo render_title($resource); ?>
        </span>
      </div>
    <?php } else { ?>
      <h1><?php echo $title; ?></h1>
    <?php } ?>
  </header>
<?php end_slot(); ?>

<?php slot('content'); ?>

  <?php
      // Textos de ayuda que la acción de upstream genera en inglés. Si el catálogo i18n del plugin ya los traduce, este mapa no se usa.
      $ucHelpEs = [
          'Choosing "Include descendants" will include all lower-level records beneath those currently on the clipboard in the export.' => 'Al elegir «Incluir descendientes», la exportación incluirá todos los registros de nivel inferior que dependen de los que están en el portapapeles.',
          'Choosing "Include draft records" will include those marked with a Draft publication status in the export. Note: if you do NOT choose this option, any descendants of a draft record will also be excluded, even if they are published.' => 'Al elegir «Incluir borradores de registros», la exportación incluirá los registros con estado de publicación «Borrador». Nota: si NO elige esta opción, también se excluirán los descendientes de un registro en borrador, aunque estén publicados.',
          'Choosing "Include non-visible elements" will include those not marked as Visible Elements.' => 'Al elegir «Incluir elementos no visibles», la exportación incluirá los elementos que no están marcados como elementos visibles.',
          'It is not possible to select both digital objects and descendants for export at the same time. Digital objects can only be exported for records that are on the clipboard.' => 'No es posible exportar a la vez objetos digitales y descendientes. Los objetos digitales solo se pueden exportar para los registros que están en el portapapeles.',
          'Digital objects with restricted access or copyright will not be exported.' => 'No se exportarán los objetos digitales con acceso restringido o derechos de autor.',
      ];
  ?>

  <div class="ah-action-page ah-action-page--export">

  <?php echo $form->renderFormTag(url_for(['module' => 'clipboard', 'action' => 'export']), ['id' => 'clipboard-export-form']); ?>

    <?php echo $form->renderHiddenFields(); ?>

    <div id="export-options" data-export-toggle="tooltip" data-export-title="<?php echo __('Export'); ?>" data-export-alert-close="<?php echo __('Close'); ?>" data-export-alert-message="<?php echo __('Error: debe seleccionar al menos un %1%nivel de descripción%2% o elegir %1%Incluir todos los niveles de descripción de los descendientes%2% para continuar.', ['%1%' => '<strong>', '%2%' => '</strong>']); ?>">
      <div class="accordion mb-3">
        <div class="accordion-item">
          <h2 class="accordion-header" id="export-heading">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#export-collapse" aria-expanded="true" aria-controls="export-collapse">
              <?php echo __('Export options'); ?>
            </button>
          </h2>
          <div id="export-collapse" class="accordion-collapse collapse show" aria-labelledby="export-heading">
            <div class="accordion-body">
              <?php /* OCULTO-039
                     SERVÍA PARA: elegir el tipo de registro a exportar (descripción archivística, registro de autoridad, institución archivística o ingreso).
                     MOTIVO: el archivo solo maneja descripciones archivísticas; no maneja registros de autoridad, instituciones archivísticas ni ingresos. El campo sigue en la página, oculto y con una sola opción, porque el script del portapapeles lo lee y se envía con el formulario.
                     Código original:
                     <?php echo render_field($form->type); ?>
                  */ ?>
              <?php /* OCULTO-040
                     SERVÍA PARA: elegir el formato de la exportación (CSV o XML).
                     MOTIVO: el archivo exporta solo en CSV. El campo sigue en la página, oculto y con la única opción CSV, para que se envíe con el formulario.
                     Código original:
                     <?php echo render_field($form->format); ?>
                  */ ?>
              <?php
                  $form->type->getWidget()->setOption('choices', ['informationObject' => sfConfig::get('app_ui_label_informationobject')]);
                  $form->format->getWidget()->setOption('choices', ['csv' => __('CSV')]);
              ?>
              <dl class="ah-export-summary">
                <div>
                  <dt><?php echo __('Type'); ?></dt>
                  <dd><?php echo sfConfig::get('app_ui_label_informationobject'); ?></dd>
                </div>
                <div>
                  <dt><?php echo __('Format'); ?></dt>
                  <dd><?php echo __('CSV'); ?></dd>
                </div>
              </dl>
              <div hidden>
                <?php echo render_field($form->type); ?>
                <?php echo render_field($form->format); ?>
              </div>
              <?php if ($showOptions) { ?>
                <div id="exportOptions">
                  <?php if (!empty($helpMessages)) { ?>
                    <div class="generic-help-box">
                      <a href="#" class="generic-help-icon" aria-expanded="false" aria-label="<?php echo __('Help'); ?>">
                        <i class="fa fa-question-circle float-end pt-1" aria-hidden="true"></i>
                      </a>
                    </div>
                  <?php } ?>
                  <?php if (isset($form->includeDrafts)) { ?>
                    <?php echo render_field($form->includeDrafts); ?>
                  <?php } ?>
                  <?php if (isset($form->includeDescendants)) { ?>
                    <?php echo render_field($form->includeDescendants); ?>
                  <?php } ?>
                  <?php if (isset($form->includeAllLevels)) { ?>
                    <div>
                      <?php echo render_field($form->includeAllLevels); ?>
                    </div>
                  <?php } ?>
                  <?php if (isset($form->levels)) { ?>
                    <div id="exportLevels">
                      <?php /* OCULTO-041
                             SERVÍA PARA: elegir, entre todos los niveles de descripción de la taxonomía, los de las descripciones descendientes que se incluyen en la exportación.
                             MOTIVO: por ahora el archivo maneja los niveles Fondo, Sección, Serie y Subserie (el nivel Asunto queda pendiente de aclarar); los demás niveles se excluyen de la lista por condición.
                             Código original: la lista mostraba todos los niveles de la taxonomía.
                          */ ?>
                      <?php
                          $allowedLevels = ['fondo', 'sección', 'serie', 'subserie'];
                          $levelsWidget = $form->levels->getWidget();
                          $levelChoices = array_filter(
                              $levelsWidget->getChoices(),
                              static function ($name) use ($allowedLevels) {
                                  return in_array(mb_strtolower((string) $name), $allowedLevels, true);
                              }
                          );
                          $levelsWidget->setOption('choices', $levelChoices);
                          $levelsWidget->setAttribute('size', max(count($levelChoices), 4));
                          $levelsLabel = __('Seleccione los niveles de las descripciones descendientes que se incluirán');
                          $levelsHelp = __('Si no se selecciona ningún nivel, la exportación fallará. Puede usar las teclas Control (⌘ en Mac) y/o Mayús para seleccionar varios valores del menú de niveles de descripción. Es necesario incluir los niveles que están por encima del nivel que desea exportar, hasta el nivel del registro que está en el portapapeles inclusive. De lo contrario, no se incluirá ningún registro en la exportación.');
                          $levelsWidget->setLabel($levelsLabel);
                          $levelsWidget->setOption('help', $levelsHelp);
                          $form->getWidgetSchema()->setHelp('levels', $levelsHelp);
                      ?>
                      <?php echo render_field($form->levels); ?>
                    </div>
                  <?php } ?>
                  <?php if (isset($form->includeDigitalObjects)) { ?>
                    <?php echo render_field($form->includeDigitalObjects); ?>
                  <?php } ?>
                  <?php if (isset($form->includeNonVisibleElements)) { ?>
                    <?php $form->includeNonVisibleElements->getWidget()->setLabel(__('Incluir elementos no visibles')); ?>
                    <?php echo render_field($form->includeNonVisibleElements); ?>
                  <?php } ?>
                  <?php if (!empty($helpMessages)) { ?>
                    <div class="alert alert-info generic-help animateNicely hidden">
                      <?php foreach ($sf_data->getRaw('helpMessages') as $helpMessage) { ?>
                        <p><?php echo isset($ucHelpEs[$helpMessage]) ? $ucHelpEs[$helpMessage] : $helpMessage; ?></p>
                      <?php } ?>
                    </div>
                  <?php } ?>
                </div>
              <?php } ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <ul class="actions ah-action-page__actions mb-3 nav gap-2">
      <li><input class="btn atom-btn-outline-success" type="submit" id="exportSubmit" value="<?php echo __('Export'); ?>"></li>
      <li><?php echo link_to(__('Cancel'), !empty($sf_request->getReferer()) ? $sf_request->getReferer() : ['module' => 'clipboard', 'action' => 'view'], ['class' => 'btn atom-btn-outline-light', 'role' => 'button']); ?></li>
    </ul>

  </form>

  </div>

<?php end_slot(); ?>
