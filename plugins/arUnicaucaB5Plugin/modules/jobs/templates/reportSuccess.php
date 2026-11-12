<?php decorate_with('layout_1col'); ?>
<?php use_helper('UcJobs'); ?>

<?php slot('title'); ?>
  <header class="ah-action-page__header">
    <p class="ah-action-page__kicker"><?php echo __('Gestión de exportaciones'); ?></p>
    <h1><?php echo __('Job report'); ?></h1>
  </header>
<?php end_slot(); ?>

<div class="ah-jobs ah-job-report">
<section id="report-overview-area" class="border-bottom">
  <?php echo render_b5_section_heading(__('Resumen')); ?>

  <?php echo render_show(__('Name'), render_value_inline($job->name)); ?>

  <?php echo render_show(__('Id'), render_value_inline($job->id)); ?>

  <?php echo render_show(__('Creation date'), render_value_inline($job->getCreationDateString())); ?>

  <?php echo render_show(__('Completion date'), render_value_inline($job->getCompletionDateString())); ?>

  <?php
      $icon = '';
      $statusModifier = 'unknown';
      if (QubitTerm::JOB_STATUS_COMPLETED_ID == $job->statusId) {
          $icon = '<i aria-hidden="true" class="fa fa-check-square ms-2 text-success"></i>';
          $statusModifier = 'completed';
      } elseif (QubitTerm::JOB_STATUS_ERROR_ID == $job->statusId) {
          $icon = '<i aria-hidden="true" class="fa fa-exclamation-triangle ms-2 text-danger"></i>';
          $statusModifier = 'failed';
      } elseif (QubitTerm::JOB_STATUS_IN_PROGRESS_ID == $job->statusId) {
          $icon = '<i aria-hidden="true" class="fa fa-cogs ms-2 text-secondary"></i>';
          $statusModifier = 'active';
      }
      $status = '<span class="ah-jobs__status ah-jobs__status--'.$statusModifier.'">'.render_value_inline(uc_job_status_label($job)).$icon.'</span>';
      echo render_show(__('Status'), $status);
  ?>

  <?php echo render_show(__('Creator'), render_value_inline(QubitJob::getUserString($job))); ?>

  <?php if ($job->getObjectModule() && $job->getObjectSlug()) { ?>
    <?php echo render_show(__('Associated record'), link_to(__('Link'), ['module' => $job->getObjectModule(), 'slug' => $job->getObjectSlug()])); ?>
  <?php } ?>

  <?php if ($job->downloadPath) { ?>
    <?php echo render_show(__('Download path'), link_to(__('Link'), public_path($job->downloadPath))); ?>
  <?php } ?>
</section>

<section id="log-area" class="border-bottom">
  <?php if (!empty($errorOutput)) { ?>
    <div class="border-bottom">
      <?php echo render_b5_section_heading(__('Error(s)')); ?>
      <div class="alert alert-danger m-2" role="alert">
        <pre id="job-log-error-output"><?php echo render_value_inline($errorOutput); ?></pre>
      </div>
    </div>
  <?php } ?>

  <?php echo render_b5_section_heading(__('Log')); ?>
  <div class="bg-secondary text-white rounded p-3 m-2">
    <?php $output = trim($job->output); ?>
    <?php if (0 < strlen($output)) { ?>
      <pre id="job-log-output" class="mb-0"><?php echo $output; ?></pre>
    <?php } else { ?>
      <p id="job-log-output-empty"><?php echo __('Empty'); ?></p>
    <?php } ?>
  </div>
</section>
</div>

<?php slot('after-content'); ?>
  <section class="actions ah-action-page__actions mb-3">
    <?php echo link_to(__('Volver'), ['module' => 'jobs', 'action' => 'browse'], ['class' => 'btn atom-btn-outline-light']); ?>
  </section>
<?php end_slot(); ?>
