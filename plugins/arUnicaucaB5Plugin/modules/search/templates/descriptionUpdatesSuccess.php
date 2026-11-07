<?php decorate_with('layout_1col'); ?>
<?php use_helper('Date'); ?>
<?php use_helper('Text'); ?>

<?php
// Fecha con el formato del diseño: "25 de octubre de 2026 a las 10:10 p. m."
$formatUpdateDate = function ($date) {
    if (empty($date)) {
        return '';
    }

    return format_date($date, 'D').' '.__('a las').' '.format_date($date, 'h:mm a');
};
$dateColumnLabel = ('CREATED_AT' != $form->getValue('dateOf')) ? __('Updated') : __('Created');
$hasResults = isset($pager) && $pager->getNbResults();
// Filtros inválidos (p. ej. «Fin» anterior a «Inicio»): no se busca, el panel queda abierto y se explica el motivo.
$hasFilterErrors = $form->hasErrors();
?>

<?php slot('title'); ?>

  <div class="ah-updates-header">
    <span class="ah-updates-header-icon" aria-hidden="true">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none" focusable="false">
        <path d="M4.5 8V4.5M4.5 4.5H8M4.5 4.5A9 9 0 1 1 3 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </span>
    <div>
      <p class="ah-updates-eyebrow"><?php echo __('Administración'); ?></p>
      <h1 aria-describedby="heading-label">
        <?php if ($hasResults) { ?>
          <?php echo __('Showing %1% results', ['%1%' => $pager->getNbResults()]); ?>
        <?php } else { ?>
          <?php echo __('No results found'); ?>
        <?php } ?>
      </h1>
      <span class="ah-updates-subtitle" id="heading-label">
        <?php echo __('Newest additions'); ?>
      </span>
      <div class="ah-updates-accent" aria-hidden="true"></div>
    </div>
  </div>
<?php end_slot(); ?>

<?php slot('content'); ?>
  <?php echo get_partial('search/updatesSearch', [
      'form' => $form,
      'show' => $showForm || $hasFilterErrors,
      'user' => $user,
  ]); ?>

  <?php if (
      'QubitInformationObject' == $className
      && sfConfig::get('app_audit_log_enabled', false)
      && $hasResults
  ) { ?>

    <div class="ah-updates-panel ah-updates-results">
      <div class="table-responsive">
        <table class="ah-updates-table">
          <thead>
            <tr>
              <th class="ah-updates-col-title"><?php echo __('Title'); ?></th>
              <th class="ah-updates-col-meta"><?php echo __('Repository'); ?></th>
              <th class="ah-updates-col-meta"><?php echo $dateColumnLabel; ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pager->getResults() as $result) { ?>
              <?php $io = QubitInformationObject::getById($result->objectId); ?>
              <?php if (null === $io) {
                  continue;
              } ?>
              <tr>
                <td class="ah-updates-title" data-label="<?php echo __('Title'); ?>">
                  <?php echo link_to(render_title($io), [
                      'slug' => $io->slug,
                      'module' => 'informationobject',
                  ]); ?>
                </td>
                <td class="ah-updates-strong" data-label="<?php echo __('Repository'); ?>">
                  <?php if (!empty($io->repository)) { ?>
                    <?php echo link_to(render_title($io->repository->authorizedFormOfName), [
                        'slug' => $io->repository->slug,
                        'module' => 'repository',
                    ]); ?>
                  <?php } ?>
                </td>
                <td class="ah-updates-date" data-label="<?php echo $dateColumnLabel; ?>">
                  <?php echo $formatUpdateDate($result->createdAt); ?>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php } elseif ($hasResults) { ?>

    <?php
    $showRepositoryColumn = 'QubitInformationObject' == $className;
    $showTaxonomyColumn = 'QubitTerm' == $className;
    $titleColumnClass = ($showRepositoryColumn || $showTaxonomyColumn) ? 'ah-updates-col-title' : '';
    ?>

    <div class="ah-updates-panel ah-updates-results">
      <div class="table-responsive">
        <table class="ah-updates-table">
          <thead>
            <tr>
              <th class="<?php echo $titleColumnClass; ?>"><?php echo __($nameColumnDisplay); ?></th>
              <?php if ($showRepositoryColumn) { ?>
                <th class="ah-updates-col-meta"><?php echo __('Repository'); ?></th>
              <?php } elseif ($showTaxonomyColumn) { ?>
                <th class="ah-updates-col-meta"><?php echo __('Taxonomy'); ?></th>
              <?php } ?>
              <th class="ah-updates-col-meta"><?php echo $dateColumnLabel; ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pager->getResults() as $result) { ?>
              <?php $doc = $result->getData(); ?>
              <tr>
                <td class="ah-updates-title" data-label="<?php echo __($nameColumnDisplay); ?>">
                  <?php if ('QubitInformationObject' == $className) { ?>

                    <?php echo link_to(
                        render_title(get_search_i18n($doc, 'title', ['allowEmpty' => false])),
                        ['slug' => $doc['slug'], 'module' => 'informationobject']
                    ); ?>
                    <?php $status = (isset($doc['publicationStatusId']))
                        ? QubitTerm::getById($doc['publicationStatusId'])
                        : null;
                    ?>
                    <?php if (isset($status) && QubitTerm::PUBLICATION_STATUS_DRAFT_ID == $status->id) { ?>
                      <span class="ah-updates-draft">
                        <?php echo ' ('.render_value_inline($status).')'; ?>
                      </span>
                    <?php } ?>

                  <?php } elseif ('QubitActor' == $className) { ?>

                    <?php echo link_to(
                        render_title(get_search_i18n($doc, 'authorizedFormOfName', ['allowEmpty' => false])),
                        ['slug' => $doc['slug'], 'module' => 'actor']
                    ); ?>

                  <?php } elseif ('QubitFunctionObject' == $className) { ?>

                    <?php echo link_to(
                        render_title(get_search_i18n($doc, 'authorizedFormOfName', ['allowEmpty' => false])),
                        ['slug' => $doc['slug'], 'module' => 'function']
                    ); ?>

                  <?php } elseif ('QubitRepository' == $className) { ?>

                    <?php echo link_to(
                        render_title(get_search_i18n($doc, 'authorizedFormOfName', ['allowEmpty' => false])),
                        ['slug' => $doc['slug'], 'module' => 'repository']
                    ); ?>

                  <?php } elseif ('QubitTerm' == $className) { ?>

                    <?php echo link_to(
                        render_title(get_search_i18n($doc, 'name', ['allowEmpty' => false])),
                        ['slug' => $doc['slug'], 'module' => 'term']
                    ); ?>

                  <?php } ?>
                </td>

                <?php if ($showRepositoryColumn) { ?>
                  <td class="ah-updates-strong" data-label="<?php echo __('Repository'); ?>">
                    <?php if (isset($doc['repository'])) { ?>
                      <?php echo render_title(get_search_i18n(
                          $doc['repository'],
                          'authorizedFormOfName',
                          ['allowEmpty' => false]
                      )); ?>
                    <?php } ?>
                  </td>
                <?php } elseif ($showTaxonomyColumn) { ?>
                  <td class="ah-updates-strong" data-label="<?php echo __('Taxonomy'); ?>">
                    <?php echo render_title(get_search_i18n($doc, 'name', ['allowEmpty' => false])); ?>
                  </td>
                <?php } ?>

                <td class="ah-updates-date" data-label="<?php echo $dateColumnLabel; ?>">
                  <?php if ('CREATED_AT' != $form->getValue('dateOf')) { ?>
                    <?php echo $formatUpdateDate($doc['updatedAt']); ?>
                  <?php } else { ?>
                    <?php echo $formatUpdateDate($doc['createdAt']); ?>
                  <?php } ?>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php } else { ?>

    <div class="ah-updates-panel ah-updates-empty">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
        <circle cx="10.5" cy="10.5" r="6" stroke="currentColor" stroke-width="1.8"/>
        <path d="m15 15 4.5 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
      </svg>
      <?php if ($hasFilterErrors) { ?>
        <h2><?php echo __('Revise los filtros'); ?></h2>
        <?php foreach ($form->getErrorSchema()->getErrors() as $filterError) { ?>
          <p><?php echo $filterError->getMessage(); // Ya escapado por escaping_strategy. ?></p>
        <?php } ?>
      <?php } else { ?>
        <h2><?php echo __('No se encontraron incorporaciones'); ?></h2>
        <p><?php echo __('Ajuste o elimine los filtros para consultar el historial.'); ?></p>
      <?php } ?>
      <?php echo link_to(__('Limpiar filtros'), ['module' => 'search', 'action' => 'descriptionUpdates'], ['class' => 'ah-updates-btn ah-updates-btn-primary ah-updates-empty-action']); ?>
    </div>

  <?php } ?>
<?php end_slot(); ?>

<?php slot('after-content'); ?>
  <?php if (isset($pager)) { ?>
    <?php echo get_partial('default/pager', ['pager' => $pager]); ?>
  <?php } ?>
<?php end_slot(); ?>
