<?php decorate_with('layout_2col.php'); ?>

<?php slot('sidebar'); ?>
  <?php echo get_partial('search/searchOptions', ['active' => 'basic']); ?>
<?php end_slot(); ?>

<?php slot('title'); ?>
  <header class="ah-action-page__header">
    <p class="ah-action-page__kicker"><?php echo __('Gestión archivística'); ?></p>
    <h1><?php echo __('Are you sure you want to delete %1%?', ['%1%' => render_title($resource)]); ?></h1>
  </header>
<?php end_slot(); ?>

<?php slot('content'); ?>
  <div class="ah-action-page">
    <?php echo $form->renderGlobalErrors(); ?>

    <?php echo $form->renderFormTag(url_for([$resource, 'module' => 'informationobject', 'action' => 'delete']), ['method' => 'delete']); ?>

      <?php echo $form->renderHiddenFields(); ?>

      <?php if (0 < $count) { ?>
        <section class="ah-action-page__panel">
          <p><?php echo __('It has %1% descendants that will also be deleted:', ['%1%' => $count]); ?></p>
          <ul class="mb-0">
            <?php foreach ($resource->descendants as $index => $item) { ?>
              <li><?php echo link_to(render_title($item), [$item, 'module' => 'informationobject']); ?></li>
              <?php if ($index + 1 == $previewSize) { ?>
                <?php break; ?>
              <?php } ?>
            <?php } ?>
          </ul>

          <?php if ($previewIsLimited) { ?>
            <div class="alert alert-warning mt-3 mb-0">
              <?php echo __('Only %1% descriptions were shown.', ['%1%' => $previewSize]); ?>
              <?php echo link_to(
                  __('View the full list of descendants.'),
                  ['module' => 'informationobject', 'action' => 'browse', 'collection' => $resource->id, 'topLod' => false],
                  ['class' => 'alert-link']
              ); ?>
            </div>
          <?php } ?>
        </section>
      <?php } ?>

      <ul class="actions ah-action-page__actions mb-3 nav gap-2">
        <li><?php echo link_to(__('Cancel'), [$resource, 'module' => 'informationobject'], ['class' => 'btn atom-btn-outline-light', 'role' => 'button']); ?></li>
        <li><input class="btn atom-btn-outline-danger" type="submit" value="<?php echo __('Delete'); ?>"></li>
      </ul>

    </form>
  </div>
<?php end_slot(); ?>
