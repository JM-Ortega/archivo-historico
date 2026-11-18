<?php decorate_with('layout_2col'); ?>

<?php slot('sidebar'); ?>
  <?php echo get_partial('search/searchOptions', ['active' => 'basic']); ?>
<?php end_slot(); ?>

<?php slot('title'); ?>
  <header class="ah-action-page__header ah-clipboard-load__header" style="position:relative;margin-bottom:1rem;padding:1.5rem;overflow:hidden;color:#fff;background:linear-gradient(112deg,#00345f 0%,#004783 70%,#0068ad 70%);border-radius:.75rem;box-shadow:0 .5rem 1.25rem rgba(0,52,95,.16)">
    <p class="ah-action-page__kicker" style="color:rgba(255,255,255,.78)"><?php echo __('Gestión archivística'); ?></p>
    <h1 style="color:#fff !important;margin:0"><?php echo __('Load clipboard'); ?></h1>
    <p class="ah-action-page__resource" style="color:rgba(255,255,255,.78)">
      <?php echo __('Restore a previously saved clipboard'); ?>
    </p>
  </header>
<?php end_slot(); ?>

<?php slot('content'); ?>
  <div class="ah-action-page ah-clipboard-load">
    <div class="ah-clipboard-load__notice" style="display:flex;gap:.75rem;align-items:flex-start;margin-bottom:1rem;padding:.75rem 1rem;color:#00345f;background:#e7f0f6;border:1px solid #b8d6e9;border-left:3px solid #0068ad;border-radius:.375rem">
      <i class="fas fa-info-circle" aria-hidden="true"></i>
      <div>
        <p><?php echo __('Enter the ID of the saved clipboard you would like to load.'); ?></p>
        <p><?php echo __('In the "Action" selector, indicate whether you want to <strong>merge</strong> the saved clipboard with the entries on the current clipboard or <strong>replace</strong> (overwrite) the current clipboard with the saved one.'); ?></p>
      </div>
    </div>

    <?php echo $form->renderFormTag(url_for(['module' => 'clipboard', 'action' => 'load']), ['id' => 'clipboard-load-form']); ?>
      <?php echo $form->renderHiddenFields(); ?>

      <section class="ah-action-page__panel" style="margin-bottom:1rem;padding:1.5rem;background:#fffefa;border:1px solid #b8d6e9;border-radius:.75rem;box-shadow:0 .25rem .75rem rgba(0,52,95,.08)">
        <h2><?php echo __('Load options'); ?></h2>
        <?php echo render_field($form->clipboardPassword->label(__('Clipboard ID'))); ?>
        <?php echo render_field($form->mode->label(__('Action'))); ?>
      </section>

      <ul class="actions ah-action-page__actions mb-3 nav gap-2" style="display:flex;margin-top:1rem;margin-bottom:1rem;padding:.75rem;background:#00345f;border-radius:.375rem">
        <li><input class="btn atom-btn-outline-light" type="submit" name="load" value="<?php echo __('Load'); ?>"/></li>
        <li><input class="btn atom-btn-outline-light" type="submit" name="loadView" value="<?php echo __('Load and view'); ?>"/></li>
      </ul>
    </form>
  </div>
<?php end_slot(); ?>
