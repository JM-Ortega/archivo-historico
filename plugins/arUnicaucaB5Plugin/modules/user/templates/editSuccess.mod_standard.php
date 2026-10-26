<?php decorate_with('layout_1col.php'); ?>

<?php $resourceAlreadyExists = isset($sf_request->getAttribute('sf_route')->resource); ?>

<?php slot('title'); ?>
  <div class="ah-user-admin">
    <a class="ah-user-back" href="<?php echo $resourceAlreadyExists
        ? url_for([$resource, 'module' => 'user'])
        : url_for(['module' => 'user', 'action' => 'list']); ?>">
      <i class="fas fa-chevron-left" aria-hidden="true"></i>
      <?php echo $resourceAlreadyExists ? __('Volver al detalle del usuario') : __('Volver a usuarios'); ?>
    </a>
    <header class="ah-user-page-head">
      <p class="ah-user-eyebrow"><?php echo __('Administración del sistema'); ?></p>
      <h1>
        <?php if ($resourceAlreadyExists) { ?>
          <?php echo __('Editar usuario %1%', ['%1%' => render_title($resource)]); ?>
        <?php } else { ?>
          <?php echo __('Crear nuevo usuario'); ?>
        <?php } ?>
      </h1>
      <p class="ah-user-lead">
        <?php echo $resourceAlreadyExists
            ? __('Modifique los datos de la cuenta, su estado y los grupos a los que pertenece.')
            : __('Registre la información básica y asigne los grupos que definirán el acceso de la nueva cuenta.'); ?>
      </p>
    </header>
  </div>
<?php end_slot(); ?>

<?php slot('content'); ?>
<div class="ah-user-admin">

  <?php if ($form->hasErrors() || $form->hasGlobalErrors()) { ?>
    <div class="alert alert-danger" role="alert">
      <strong><?php echo __('No se pudo guardar el usuario.'); ?></strong>
      <?php echo __('Revise los campos marcados e inténtelo de nuevo.'); ?>
    </div>
  <?php } ?>

  <?php echo $form->renderGlobalErrors(); ?>

  <?php if ($resourceAlreadyExists) { ?>
    <?php echo $form->renderFormTag(url_for([$resource, 'module' => 'user', 'action' => 'edit']), ['id' => 'editForm']); ?>
  <?php } else { ?>
    <?php echo $form->renderFormTag(url_for(['module' => 'user', 'action' => 'add']), ['id' => 'editForm']); ?>
  <?php } ?>

    <?php echo $form->renderHiddenFields(); ?>

    <section class="ah-user-panel" aria-labelledby="ah-user-basic-title">
      <div class="ah-user-panel-head">
        <span class="ah-user-panel-icon"><i class="fas fa-user" aria-hidden="true"></i></span>
        <div>
          <h2 id="ah-user-basic-title"><?php echo __('Información básica'); ?></h2>
          <p><?php echo __('Datos necesarios para identificar y autenticar al usuario.'); ?></p>
        </div>
      </div>
      <div class="ah-user-panel-body">
        <div class="ah-user-grid">
          <div><?php echo render_field($form->username->label(__('Nombre de usuario'))); ?></div>
          <div>
            <?php echo render_field($form->email, null, ['type' => 'email', 'aria-describedby' => 'ah-user-email-hint']); ?>
            <p id="ah-user-email-hint" class="ah-user-hint"><?php echo esc_entities(arUnicaucaEmailDomainPolicy::message()); ?></p>
          </div>
        </div>

        <div
          hidden
          class="password-strength-settings"
          data-not-strong="<?php echo __('Your password is not strong enough.'); ?>"
          data-strength-title="<?php echo __('Password strength:'); ?>"
          data-require-strong-password="<?php echo sfConfig::get('app_require_strong_passwords', false); ?>"
          data-too-short="<?php echo __('Make it at least six characters'); ?>"
          data-add-lower-case="<?php echo __('Add lowercase letters'); ?>"
          data-add-upper-case="<?php echo __('Add uppercase letters'); ?>"
          data-add-numbers="<?php echo __('Add numbers'); ?>"
          data-add-punctuation="<?php echo __('Add punctuation'); ?>"
          data-username="<?php echo $resource->username; ?>"
          data-same-as-username="<?php echo __('Make it different from your username'); ?>"
          data-confirm-failure="<?php echo __('Your password confirmation did not match your password.'); ?>"
        >
        </div>

        <?php if ($resourceAlreadyExists) { ?>
          <p class="ah-user-note">
            <?php echo __('Deje la nueva contraseña en blanco para conservar la actual. Para guardar los cambios debe confirmar su identidad con su propia contraseña.'); ?>
          </p>
        <?php } ?>

        <?php if (sfConfig::get('app_require_strong_passwords')) { ?>
          <p class="ah-user-hint"><?php echo __('La contraseña debe tener al menos 8 caracteres y combinar al menos tres de estos tipos: mayúsculas, minúsculas, números y símbolos.'); ?></p>
        <?php } ?>

        <div class="ah-user-grid">
          <?php echo render_field($form->password->label(__('New password')), null, ['class' => 'password-strength']); ?>
          <?php echo render_field($form->confirmPassword->label(__('Confirmar contraseña')), null, ['class' => 'password-confirm']); ?>
        </div>

        <div class="template ah-user-strength" hidden>
          <div class="ah-user-note">
            <label class="form-label"><?php echo __('Password strength:'); ?></label>
            <div class="progress mb-2">
              <div class="progress-bar w-0" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
          </div>
        </div>

        <?php if ($resourceAlreadyExists) { ?>
          <div class="ah-user-grid">
            <?php echo render_field($form->currentPassword->label(__('Su contraseña actual (%1%)', ['%1%' => $sf_user->user->username])), null); ?>
          </div>
        <?php } ?>

        <?php if ($sf_user->getAttribute('user_id') != $resource->id) { ?>
          <?php echo render_field($form->active->label(__('Active'))); ?>
        <?php } else { ?>
          <p class="ah-user-note"><?php echo __('No puede desactivar su propia cuenta.'); ?></p>
        <?php } ?>
      </div>
    </section>

    <section class="ah-user-panel" aria-labelledby="ah-user-access-title">
      <div class="ah-user-panel-head">
        <span class="ah-user-panel-icon"><i class="fas fa-lock" aria-hidden="true"></i></span>
        <div>
          <h2 id="ah-user-access-title"><?php echo __('Access control'); ?></h2>
          <p><?php echo __('Los grupos asignados y la configuración individual de la cuenta determinan su acceso.'); ?></p>
        </div>
      </div>
      <div class="ah-user-panel-body">
        <p class="ah-user-note">
          <?php echo $resourceAlreadyExists
              ? __('Aquí solo se asignan grupos. Los permisos también pueden tener configuraciones individuales, que no se editan en este formulario; consúltelas en la vista de permisos del usuario.')
              : __('Aquí solo se asignan grupos. Los permisos también pueden tener configuraciones individuales, que no se editan en este formulario; tras crear la cuenta podrá consultarlas en la vista de permisos del usuario.'); ?>
        </p>

        <?php echo render_field(
            $form->groups->label(__('User groups')),
            null,
            ['class' => 'form-autocomplete']
        ); ?>

        <?php echo render_field(
            $form->translate->label(__('Allowed languages for translation')),
            null,
            ['class' => 'form-autocomplete']
        ); ?>

        <?php if ($restEnabled) { ?>
          <?php echo render_field($form->restApiKey->label(
              __('REST API access key'.((isset($restApiKey)) ? ': <code class="ms-2">'.$restApiKey.'</code>' : ''))
          )); ?>
        <?php } ?>

        <?php if ($oaiEnabled) { ?>
          <?php echo render_field($form->oaiApiKey->label(
              __('OAI-PMH API access key'.((isset($oaiApiKey)) ? ': <code class="ms-2">'.$oaiApiKey.'</code>' : ''))
          )); ?>
        <?php } ?>
      </div>
    </section>

    <ul class="actions ah-user-actions nav gap-2">
      <?php if ($resourceAlreadyExists) { ?>
        <li><?php echo link_to(__('Cancel'), [$resource, 'module' => 'user'], ['class' => 'btn atom-btn-outline-light', 'role' => 'button']); ?></li>
        <li><input class="btn atom-btn-outline-success" type="submit" value="<?php echo __('Save'); ?>"></li>
      <?php } else { ?>
        <li><?php echo link_to(__('Cancel'), ['module' => 'user', 'action' => 'list'], ['class' => 'btn atom-btn-outline-light', 'role' => 'button']); ?></li>
        <li><input class="btn atom-btn-outline-success" type="submit" value="<?php echo __('Crear usuario'); ?>"></li>
      <?php } ?>
    </ul>

  </form>
</div>
<?php end_slot(); ?>
