<?php decorate_with('layout_1col.php'); ?>

<?php slot('title'); ?>
  <header class="ah-page-header ah-clipboard-export-header">
    <p class="ah-page-kicker"><?php echo __('Portapapeles'); ?></p>
    <h1><?php echo __('Exportación del portapapeles'); ?></h1>
  </header>
<?php end_slot(); ?>

<?php slot('content'); ?>
  <section class="ah-clipboard-export" aria-labelledby="ah-clipboard-export-title">
    <?php echo $form->renderFormTag(url_for(['module' => 'clipboard', 'action' => 'export']), ['id' => 'clipboard-export-form', 'class' => 'ah-clipboard-export__form']); ?>

      <?php echo $form->renderHiddenFields(); ?>
      <input type="hidden" name="format" value="csv">

      <div id="export-options" data-export-toggle="tooltip" data-export-title="<?php echo __('Export'); ?>" data-export-alert-close="<?php echo __('Close'); ?>" data-export-alert-message="<?php echo __('Error: You must have at least one %1%Level of description%2% selected or choose %1%Include all descendant levels of description%2% to proceed.', ['%1%' => '<strong>', '%2%' => '</strong>']); ?>">
        <div class="ah-clipboard-export__panel">
          <h2 id="ah-clipboard-export-title" class="ah-clipboard-export__title"><?php echo __('Opciones de exportación'); ?></h2>
          <div class="ah-clipboard-export__body">
            <?php echo render_field($form->type); ?>

            <div class="form-group ah-clipboard-export__format">
              <label><?php echo __('Format'); ?></label>
              <div class="ah-clipboard-export__fixed-format">
                <i class="fas fa-file-csv" aria-hidden="true"></i>
                <strong>CSV</strong>
                <span><?php echo __('Formato fijo de descarga'); ?></span>
              </div>
            </div>

            <?php if ($showOptions) { ?>
              <div id="exportOptions" class="ah-clipboard-export__options">
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
                  <?php echo render_field($form->includeAllLevels); ?>
                <?php } ?>
                <?php if (isset($form->levels)) { ?>
                  <div id="exportLevels">
                    <?php echo render_field($form->levels); ?>
                  </div>
                <?php } ?>
                <?php if (isset($form->includeDigitalObjects)) { ?>
                  <?php echo render_field($form->includeDigitalObjects); ?>
                <?php } ?>
                <?php if (isset($form->includeNonVisibleElements)) { ?>
                  <?php echo render_field($form->includeNonVisibleElements); ?>
                <?php } ?>
                <?php if (!empty($helpMessages)) { ?>
                  <div class="alert alert-info generic-help animateNicely hidden">
                    <?php foreach ($sf_data->getRaw('helpMessages') as $helpMessage) { ?>
                      <p><?php echo $helpMessage; ?></p>
                    <?php } ?>
                  </div>
                <?php } ?>
              </div>
            <?php } ?>
          </div>
        </div>
      </div>

      <ul class="actions ah-clipboard-export__actions mb-0 nav gap-2">
        <li><input class="btn atom-btn-outline-light" type="submit" id="exportSubmit" value="<?php echo __('Export'); ?>"></li>
        <li><?php echo link_to(__('Cancel'), !empty($sf_request->getReferer()) ? $sf_request->getReferer() : ['module' => 'clipboard', 'action' => 'view'], ['class' => 'btn atom-btn-outline-light', 'role' => 'button']); ?></li>
      </ul>

    </form>
  </section>
<?php end_slot(); ?>
