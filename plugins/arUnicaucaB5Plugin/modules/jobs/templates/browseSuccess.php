<?php decorate_with('layout_2col'); ?>
<?php use_helper('UcJobs'); ?>

<?php slot('sidebar'); ?>
  <?php echo get_partial('search/searchOptions', ['active' => 'export']); ?>
<?php end_slot(); ?>

<?php slot('content'); ?>
<?php $counts = uc_jobs_counts(); ?>
<?php $jobs = $pager->getResults(); ?>

<div class="ah-jobs ah-action-page">

<header class="ah-jobs__header">
  <div>
    <p class="ah-action-page__kicker"><?php echo __('Gestión de exportaciones'); ?></p>
    <div class="ah-action-page__header">
      <h1><?php echo __('Administrar procesos'); ?></h1>
    </div>
    <p class="ah-jobs__lead">
      <?php echo __('Cada búsqueda queda registrada como un proceso independiente. Los procesos completados se pueden descargar en formato CSV.'); ?>
    </p>
  </div>
  <a class="btn atom-btn-white ah-jobs__new-search" href="<?php echo url_for('@homepage'); ?>">
    <i class="fas fa-search me-1" aria-hidden="true"></i>
    <?php echo __('Nueva búsqueda'); ?>
  </a>
</header>

<nav>
  <ul class="nav nav-pills mb-3 d-flex gap-2">
    <li class="nav-item">
      <?php $options = ['class' => 'btn atom-btn-white active-primary text-wrap']; ?>
      <?php if ('all' === $filter) { ?>
        <?php $options['class'] .= ' active'; ?>
        <?php $options['aria-current'] = 'page'; ?>
      <?php } ?>
      <?php echo link_to(__('All jobs').'<span class="ah-jobs__count">'.$counts['all'].'</span>', ['filter' => 'all'] + $sf_data->getRaw('sf_request')->getParameterHolder()->getAll(), $options); ?>
    </li>
    <li class="nav-item">
      <?php $options = ['class' => 'btn atom-btn-white active-primary text-wrap']; ?>
      <?php if ('active' === $filter) { ?>
        <?php $options['class'] .= ' active'; ?>
        <?php $options['aria-current'] = 'page'; ?>
      <?php } ?>
      <?php echo link_to(__('Active jobs').'<span class="ah-jobs__count">'.$counts['active'].'</span>', ['filter' => 'active'] + $sf_data->getRaw('sf_request')->getParameterHolder()->getAll(), $options); ?>
    </li>
    <li class="nav-item">
      <?php $options = ['class' => 'btn atom-btn-white active-primary text-wrap']; ?>
      <?php if ('failed' === $filter) { ?>
        <?php $options['class'] .= ' active'; ?>
        <?php $options['aria-current'] = 'page'; ?>
      <?php } ?>
      <?php echo link_to(__('Fallidos').'<span class="ah-jobs__count">'.$counts['failed'].'</span>', ['filter' => 'failed'] + $sf_data->getRaw('sf_request')->getParameterHolder()->getAll(), $options); ?>
    </li>
  </ul>
</nav>

<div class="tab-content">
<?php /* OCULTO-036
       SERVÍA PARA: mostrar la tabla con sus encabezados aunque no hubiera procesos.
       MOTIVO: con la lista vacía solo se muestra el estado vacío del diseño.
       Código original: la tabla se mostraba siempre, sin condición.
    */ ?>
<?php if ($jobs->count()) { ?>
  <div class="table-responsive mb-3 ah-jobs__card">
    <table class="table table-bordered mb-0 ah-jobs__table">
      <thead>
        <tr>
          <th class="w-15"><?php echo __('Start date'); ?></th>
          <th class="w-15"><?php echo __('End date'); ?></th>
          <th class="w-20"><?php echo __('Job name'); ?></th>
          <th class="w-10"><?php echo __('Job status'); ?></th>
          <th class="w-30"><?php echo __('Info'); ?></th>
          <th class="w-10"><?php echo __('User'); ?></th>
        </tr>
      </thead>

      <?php foreach ($jobs as $job) { ?>
        <tr>
          <!-- Creation date -->
          <td><?php echo $job->getCreationDateString(); ?></td>

          <!-- End date -->
          <td><?php echo $job->getCompletionDateString(); ?></td>

          <!-- Job name -->
          <td><?php echo $job; ?></td>

          <!-- Job status -->
          <td class="text-nowrap">
            <?php
              $statusModifier = 'unknown';
              if (QubitTerm::JOB_STATUS_COMPLETED_ID == $job->statusId) {
                  $statusModifier = 'completed';
              } elseif (QubitTerm::JOB_STATUS_ERROR_ID == $job->statusId) {
                  $statusModifier = 'failed';
              } elseif (QubitTerm::JOB_STATUS_IN_PROGRESS_ID == $job->statusId) {
                  $statusModifier = 'active';
              }
            ?>
            <span class="ah-jobs__status ah-jobs__status--<?php echo $statusModifier; ?>">
              <?php if (QubitTerm::JOB_STATUS_COMPLETED_ID == $job->statusId) { ?>
                <i aria-hidden="true" class="fa fa-check-square me-1 text-success"></i>
              <?php } elseif (QubitTerm::JOB_STATUS_ERROR_ID == $job->statusId) { ?>
                <i aria-hidden="true" class="fa fa-exclamation-triangle me-1 text-danger"></i>
              <?php } elseif (QubitTerm::JOB_STATUS_IN_PROGRESS_ID == $job->statusId) { ?>
                <i aria-hidden="true" class="fa fa-cogs me-1 text-secondary"></i>
              <?php } ?>

              <?php echo $job->getStatusString(); ?>
            </span>

            <?php if ($job->getObjectModule() && $job->getObjectSlug()) { ?>
              <a
                href="<?php echo url_for([
                    'module' => $job->getObjectModule(),
                    'slug' => $job->getObjectSlug(),
                ]); ?>"
                class="text-decoration-none ms-1">
                <i class="fa fa-share" aria-hidden="true"></i>
                <span class="visually-hidden">
                  <?php echo __('Go to related resource'); ?>
                </span>
              </a>
            <?php } ?>
          </td>

          <!-- Job notes -->
          <td>
            <?php foreach ($job->getNotes() as $note) { ?>
              <p class="mb-2"><?php echo $note->__toString(); ?></p>
            <?php } ?>
            <?php if (isset($job->downloadPath)) { ?>
              <p class="mb-2">
                <?php echo link_to(__('Download'), public_path($job->downloadPath), ['class' => 'ah-jobs__download']); ?>
                (<?php echo hr_filesize(filesize($job->downloadPath)); ?>)
              </p>
            <?php } ?>
            <?php echo link_to(__('Full report'), ['module' => 'jobs', 'action' => 'report', 'id' => $job->id], ['class' => 'ah-jobs__link']); ?>
          </td>

          <!-- User who created the job -->
          <td>
            <?php echo esc_entities(QubitJob::getUserString($job)); ?>
          </td>
        </tr>
      <?php } ?>
    </table>
  </div>
<?php } ?>
</div>

<?php echo get_partial('default/pager', ['pager' => $pager]); ?>

<!-- User tips -->
<?php if ($this->context->user->isAdministrator() && $jobs->count()) { ?>
  <div class="alert alert-info" role="alert">
    <i class="fas fa-info-circle me-2" aria-hidden="true"></i><?php echo __('You may only clear jobs belonging to you.'); ?>
  </div>
<?php } ?>

<?php if (!$jobs->count()) { ?>
  <div class="alert alert-info ah-jobs__empty" role="alert">
    <span class="ah-jobs__empty-icon" aria-hidden="true"><i class="fas fa-download"></i></span>
    <ul class="list-unstyled m-0">
      <li><?php echo __('There are no jobs to report on.'); ?></li>
      <li class="ah-jobs__empty-hint">
        <?php echo 'all' === $filter
            ? __('Realice una búsqueda básica o avanzada para crear el primer proceso.')
            : __('Seleccione otra categoría para consultar el historial disponible.'); ?>
      </li>
    </ul>
  </div>
<?php } ?>

<!-- Action buttons -->
<ul class="actions mb-3 nav gap-2 ah-action-page__actions">
  <li>
    <a class="btn atom-btn-outline-light" data-action="refresh" href="#">
      <i class="fas fa-sync-alt me-1" aria-hidden="true"></i>
      <?php echo __('Refresh'); ?>
    </a>
  </li>
  <?php /* OCULTO-037
         SERVÍA PARA: recargar la lista automáticamente cada 10 segundos para ver el avance de los procesos.
         MOTIVO: no se considera necesario en este prototipo; la lista se actualiza con el botón «Actualizar».
         Código original:
          <li>
            <a class="btn atom-btn-outline-light" href="<?php echo url_for(
                ['module' => 'jobs', 'action' => 'browse', 'autoRefresh' => !$autoRefresh]
                + $sf_data->getRaw('sf_request')->getParameterHolder()->getAll()
            ); ?>">
              <i class="fas <?php echo $autoRefresh ? 'fa-check-circle' : 'fa-circle'; ?> me-1" aria-hidden="true"></i>
              <?php echo __('Auto refresh'); ?>
            </a>
          </li>
      */ ?>
  <?php if ($jobs->count()) { ?>
    <?php /* OCULTO-038
           SERVÍA PARA: descargar un CSV con el historial de los procesos (trabajos) que aparecen en la tabla.
           MOTIVO: no se considera necesario para el archivo en este prototipo.
           Código original:
            <li>
              <?php echo link_to(__('Export history CSV'), ['module' => 'jobs', 'action' => 'export'],
                ['class' => 'btn atom-btn-outline-light']); ?>
            </li>
        */ ?>
    <li>
      <?php echo link_to(__('Clear inactive jobs'), ['module' => 'jobs', 'action' => 'delete'],
        ['class' => 'btn atom-btn-outline-danger']); ?>
    </li>
  <?php } ?>
</ul>

</div>

<!-- Refresh after specified interval if auto-refresh enabled -->
<?php if ($autoRefresh) { ?>
  <script <?php echo __(sfConfig::get('csp_nonce', '')); ?>>
    setTimeout(function() {
      window.location.reload(1);
    }, <?php echo $refreshInterval; ?>);
  </script>
<?php } ?>
<?php end_slot(); ?>
