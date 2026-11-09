<?php decorate_with('layout_2col'); ?>

<?php slot('sidebar'); ?>
  <?php echo get_partial('search/searchOptions', ['active' => 'basic']); ?>
<?php end_slot(); ?>

<?php slot('title'); ?>
  <header class="ah-action-page__header ah-clipboard-load__header">
    <p class="ah-action-page__kicker"><?php echo __('Gestión archivística'); ?></p>
    <h1><?php echo __('Load clipboard'); ?></h1>
    <p class="ah-action-page__resource">
      <?php echo __('Restore a previously saved clipboard'); ?>
    </p>
  </header>
<?php end_slot(); ?>

<?php slot('content'); ?>
  <div class="ah-action-page ah-clipboard-load">
    <div class="ah-clipboard-load__notice">
      <i class="fas fa-info-circle" aria-hidden="true"></i>
      <div>
        <p><?php echo __('Enter the ID of the saved clipboard you would like to load.'); ?></p>
        <p><?php echo __('In the "Action" selector, indicate whether you want to <strong>merge</strong> the saved clipboard with the entries on the current clipboard or <strong>replace</strong> (overwrite) the current clipboard with the saved one.'); ?></p>
      </div>
    </div>

    <?php echo $form->renderFormTag(url_for(['module' => 'clipboard', 'action' => 'load']), ['id' => 'clipboard-load-form']); ?>
      <?php echo $form->renderHiddenFields(); ?>

      <section class="ah-action-page__panel">
        <h2><?php echo __('Load options'); ?></h2>
        <?php echo render_field($form->clipboardPassword->label(__('Clipboard ID'))); ?>
        <?php echo render_field($form->mode->label(__('Action'))); ?>
      </section>

      <ul class="actions ah-action-page__actions mb-3 nav gap-2">
        <li><input class="btn atom-btn-outline-light" type="submit" name="load" value="<?php echo __('Load'); ?>"/></li>
        <li><input class="btn atom-btn-outline-light" type="submit" name="loadView" value="<?php echo __('Load and view'); ?>"/></li>
      </ul>
    </form>
  </div>
<?php end_slot(); ?>
