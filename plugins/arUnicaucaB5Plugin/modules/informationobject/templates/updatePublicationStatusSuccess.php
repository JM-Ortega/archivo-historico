<?php decorate_with('layout_2col'); ?>

<?php slot('sidebar'); ?>
  <?php echo get_partial('search/searchOptions', ['active' => 'basic']); ?>
<?php end_slot(); ?>

<?php slot('title'); ?>
  <header class="ah-action-page__header">
    <p class="ah-action-page__kicker"><?php echo __('Gestión archivística'); ?></p>
    <h1><?php echo __('Update publication status'); ?></h1>
    <p class="ah-action-page__resource"><?php echo render_title($resource); ?></p>
  </header>
<?php end_slot(); ?>

<?php slot('content'); ?>
  <div class="ah-action-page ah-action-page--publication-status">
    <?php echo $form->renderGlobalErrors(); ?>

    <?php echo $form->renderFormTag(
      url_for(
        [
            $resource,
            'module' => 'informationobject',
            'action' => 'updatePublicationStatus',
        ]
      ),
      [
          'id' => 'update-publication-status-form',
          'data-cy' => 'update-publication-status-form',
      ]
    ); ?>

      <?php echo $form->renderHiddenFields(); ?>

      <section class="ah-action-page__panel">
        <h2><?php echo __('Update publication status'); ?></h2>
        <?php echo render_field($form->publicationStatus->label(__('Publication status'))); ?>

        <?php if ($resource->rgt - $resource->lft > 1) { ?>
          <?php echo render_field($form->updateDescendants->label(__('Update descendants'))); ?>
        <?php } ?>
      </section>

      <ul class="actions ah-action-page__actions mb-3 nav gap-2">
        <li><?php echo link_to(__('Cancel'), [$resource, 'module' => 'informationobject'], ['class' => 'btn atom-btn-outline-light', 'role' => 'button']); ?></li>
        <li><input class="btn atom-btn-outline-success" type="submit" value="<?php echo __('Update'); ?>"></li>
      </ul>

    </form>
  </div>
<?php end_slot(); ?>
