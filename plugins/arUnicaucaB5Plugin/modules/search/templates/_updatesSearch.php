<div class="accordion adv-search ah-updates-panel ah-updates-filters" role="search">
  <div class="accordion-item">
    <h2 class="accordion-header" id="heading-adv-search">
      <button class="accordion-button<?php echo $show ? '' : ' collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-adv-search" aria-expanded="<?php echo $show ? 'true' : 'false'; ?>" aria-controls="collapse-adv-search">
        <span class="ah-updates-filter-label">
          <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          </svg>
          <?php echo __('Filter options'); ?>
        </span>
        <svg class="ah-updates-chevron" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="m7 9 5 5 5-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
    </h2>
    <div id="collapse-adv-search" class="accordion-collapse collapse<?php echo $show ? ' show' : ''; ?>" aria-labelledby="heading-adv-search">
      <div class="accordion-body">
        <?php echo $form->renderFormTag(url_for(['module' => 'search', 'action' => 'descriptionUpdates']), ['name' => 'advanced-search-form', 'method' => 'get']); ?>

          <input type="hidden" name="showForm" value="1"/>

          <h3 class="ah-updates-section-title"><?php echo __('Filter results by:'); ?></h3>

          <div class="criteria row">

            <div class="col-md-6">
              <?php echo render_field($form->className->label(__('Type'))); ?>
            </div>

            <fieldset class="col-md-6">
              <legend class="ah-updates-legend"><?php echo __('Date of'); ?></legend>
              <?php foreach ($form->getWidgetSchema()->dateOf->getChoices() as $value => $translatedText) { ?>
                <div class="form-check">
                  <?php $radioID = 'dateOf-'.QubitSlug::slugify($value); ?>
                  <input class="form-check-input" id="<?php echo $radioID; ?>" type="radio" name="dateOf" value="<?php echo $value; ?>" <?php echo $form->getValue('dateOf') == $value ? 'checked' : ''; ?>>
                  <label class="form-check-label" for="<?php echo $radioID; ?>"><?php echo $translatedText; ?></label>
                </div>
              <?php } ?>
            </fieldset>

            <div class="col-md-6">
              <?php echo render_field($form->repository->label(__('Repository'))); ?>
            </div>

            <fieldset class="col-md-6">
              <legend class="ah-updates-legend"><?php echo __('Publication status'); ?></legend>
              <?php foreach ($form->getWidgetSchema()->publicationStatus->getChoices() as $value => $translatedText) { ?>
                <div class="form-check">
                  <?php $radioID = 'publicationStatus-'.QubitSlug::slugify($value); ?>
                  <input class="form-check-input" id="<?php echo $radioID; ?>" type="radio" name="publicationStatus" value="<?php echo $value; ?>" <?php echo $form->getValue('publicationStatus') == $value ? 'checked' : ''; ?>>
                  <label class="form-check-label" for="<?php echo $radioID; ?>"><?php echo $translatedText; ?></label>
                </div>
              <?php } ?>
            </fieldset>

            <?php if (sfConfig::get('app_audit_log_enabled', false)) { ?>
              <div class="col-md-6">
                <?php echo render_field(
                    $form->user->label(__('User')),
                    null,
                    [
                        'class' => 'form-autocomplete',
                        'extraInputs' => '<input class="list" type="hidden" value="'
                            .url_for([
                                'module' => 'user',
                                'action' => 'autocomplete',
                            ])
                            .'">',
                    ]
                ); ?>
                <?php if (isset($user)) { ?>
                  <div class="form-text">
                    <?php echo __('Currently displaying:'); ?> <em><?php echo $user->getUsername(); ?></em>
                  </div>
                <?php } ?>
              </div>
            <?php } ?>

          </div>

          <h3 class="ah-updates-section-title"><?php echo __('Filter by date range:'); ?></h3>

          <div class="criteria row">

            <div class="col-md-6 start-date">
              <?php echo render_field($form->startDate->label(__('Start')), null, ['type' => 'date']); ?>
            </div>

            <div class="col-md-6 end-date">
              <?php echo render_field($form->endDate->label(__('End')), null, ['type' => 'date']); ?>
            </div>

          </div>

          <ul class="actions ah-updates-actions">
            <li><input type="submit" class="ah-updates-btn ah-updates-btn-primary" value="<?php echo __('Search'); ?>"></li>
            <li><input type="button" class="ah-updates-btn ah-updates-btn-outline reset" value="<?php echo __('Reset'); ?>"></li>
          </ul>

        </form>
      </div>
    </div>
  </div>
</div>
