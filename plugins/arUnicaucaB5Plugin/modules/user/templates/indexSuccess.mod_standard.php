<div class="ah-user-admin">

  <a class="ah-user-back" href="<?php echo url_for(['module' => 'user', 'action' => 'list']); ?>">
    <i class="fas fa-chevron-left" aria-hidden="true"></i>
    <?php echo __('Volver a usuarios'); ?>
  </a>

  <header class="ah-user-page-head ah-user-page-head--split">
    <div>
      <p class="ah-user-eyebrow"><?php echo __('Administración del sistema'); ?></p>
      <h1><?php echo __('Detalles de usuario'); ?></h1>
      <p class="ah-user-lead">
        <?php echo __('Consulte la información de la cuenta y sus configuraciones de permisos por grupo e individuales.'); ?>
      </p>
    </div>
    <div class="ah-user-head-actions">
      <?php if (QubitAcl::check($resource, 'update')) { ?>
        <?php echo link_to(__('Editar usuario'), [$resource, 'module' => 'user', 'action' => 'edit'], ['class' => 'btn ah-user-btn-primary']); ?>
      <?php } ?>
      <?php if ($sf_user->getAttribute('user_id') == $resource->id) { ?>
        <?php echo link_to(__('Cambiar contraseña'), [$resource, 'module' => 'user', 'action' => 'passwordEdit'], ['class' => 'btn ah-user-btn-primary']); ?>
      <?php } ?>
    </div>
  </header>

  <section id="content">

    <section id="userDetails" class="ah-user-panel">
      <div class="ah-user-account-head">
        <span class="ah-user-avatar" aria-hidden="true"><i class="fas fa-user"></i></span>
        <div class="ah-user-account-id">
          <p class="ah-user-account-label"><?php echo __('Cuenta de usuario'); ?></p>
          <p class="ah-user-account-name"><?php echo render_value_inline($resource->username); ?></p>
        </div>
        <span class="ah-user-status <?php echo $resource->active ? 'ah-user-status--active' : 'ah-user-status--inactive'; ?>">
          <?php echo $resource->active ? __('Cuenta activa') : __('Cuenta inactiva'); ?>
        </span>
      </div>

      <?php if (!$resource->active) { ?>
        <div class="alert alert-danger ah-user-inline-alert" role="alert">
          <?php echo __('This user is inactive'); ?>
        </div>
      <?php } ?>

      <dl class="ah-user-dl">
        <div>
          <dt><?php echo __('User name'); ?></dt>
          <dd><?php echo render_value_inline($resource->username.($sf_user->getAttribute('user_id') == $resource->id ?' ('.__('you').')' : '')); ?></dd>
        </div>
        <div>
          <dt><?php echo __('Email'); ?></dt>
          <dd class="ah-user-break"><?php echo $resource->email; ?></dd>
        </div>

        <?php if (!$sf_user->isAdministrator()) { ?>
          <div>
            <dt><?php echo __('Password'); ?></dt>
            <dd><?php echo link_to(__('Reset password'), [$resource, 'module' => 'user', 'action' => 'passwordEdit']); ?></dd>
          </div>
        <?php } ?>

        <div class="ah-user-span">
          <dt><?php echo __('Grupos asignados'); ?></dt>
          <dd>
            <?php if (0 < count($groups = $resource->getAclGroups())) { ?>
              <ul class="ah-user-chips">
                <?php foreach ($groups as $group) { ?>
                  <li><?php echo render_title($group); ?></li>
                <?php } ?>
              </ul>
            <?php } else { ?>
              <span class="ah-user-muted"><?php echo __('Sin grupos asignados'); ?></span>
            <?php } ?>
          </dd>
        </div>

        <?php if (
            sfConfig::get('app_multi_repository')
            && 0 < count($repositories = $resource->getRepositories())
        ) { ?>
          <div class="ah-user-span">
            <dt><?php echo __('Repository affiliation'); ?></dt>
            <dd>
              <ul class="ah-user-chips">
                <?php foreach ($repositories as $item) { ?>
                  <li><?php echo render_title($item); ?></li>
                <?php } ?>
              </ul>
            </dd>
          </div>
        <?php } ?>

        <?php if ($sf_context->getConfiguration()->isPluginEnabled('arRestApiPlugin')) { ?>
          <div>
            <dt><?php echo __('REST API key'); ?></dt>
            <dd><?php echo isset($restApiKey) ? '<code>'.$restApiKey.'</code>' : '<span class="ah-user-muted">'.__('Not generated yet.').'</span>'; ?></dd>
          </div>
        <?php } ?>

        <?php if ($sf_context->getConfiguration()->isPluginEnabled('arOaiPlugin')) { ?>
          <div>
            <dt><?php echo __('OAI-PMH API key'); ?></dt>
            <dd><?php echo isset($oaiApiKey) ? '<code>'.$oaiApiKey.'</code>' : '<span class="ah-user-muted">'.__('Not generated yet.').'</span>'; ?></dd>
          </div>
        <?php } ?>
      </dl>
    </section>

    <section class="ah-user-panel" aria-labelledby="ah-user-perm-title">
      <div class="ah-user-panel-head">
        <span class="ah-user-panel-icon"><i class="fas fa-lock" aria-hidden="true"></i></span>
        <div>
          <h2 id="ah-user-perm-title"><?php echo __('Permisos'); ?></h2>
          <p><?php echo __('Consulte las configuraciones registradas para cada categoría.'); ?></p>
        </div>
      </div>
      <div class="ah-user-panel-body">
        <p class="ah-user-note">
          <?php echo __('Estas vistas muestran las configuraciones registradas para los grupos del usuario y para su cuenta individual. No representan un cálculo de permisos efectivos.'); ?>
        </p>
        <?php echo get_component('user', 'aclMenu', ['hideActive' => true]); ?>
      </div>
    </section>

    <?php if (sfConfig::get('app_audit_log_enabled', false)) { ?>
      <div id="editing-history-wrapper">
        <div class="accordion accordion-flush border-top hidden" id="editingHistory">
          <div class="accordion-item rounded-bottom">
            <h2 class="accordion-header" id="history-heading">
              <button class="accordion-button collapsed text-primary" type="button" data-bs-toggle="collapse" data-bs-target="#history-collapse" aria-expanded="false" aria-controls="history-collapse">
                <?php echo __('Editing history'); ?>
                <span id="editingHistoryActivityIndicator">
                  <i class="fas fa-spinner fa-spin ms-2" aria-hidden="true"></i>
                  <span class="visually-hidden"><?php echo __('Loading ...'); ?></span>
                </span>
              </button>
            </h2>
            <div id="history-collapse" class="accordion-collapse collapse" aria-labelledby="history-heading">
              <div class="accordion-body">
                <div class="table-responsive mb-3">
                  <table class="table table-bordered mb-0">
                    <thead>
                      <tr>
                        <th><?php echo __('Title'); ?></th>
                        <th><?php echo __('Date'); ?></th>
                        <th><?php echo __('Type'); ?></th>
                      </tr>
                    </thead>
                    <tbody id="editingHistoryRows">
                    </tbody>
                  </table>
                </div>

                <div class="text-end">
                  <input class="btn atom-btn-white" type="button" id='previousButton' value='<?php echo __('Previous'); ?>'>
                  <input class="btn atom-btn-white ms-2" type="button" id='nextButton' value='<?php echo __('Next'); ?>'>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php } ?>

  </section>

  <?php echo get_partial('showActions', ['resource' => $resource]); ?>
</div>
