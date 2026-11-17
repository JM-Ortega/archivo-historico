<?php if ($showLogin) { ?>
  <a class="ah-login-header-button" href="<?php echo url_for(['module' => 'user', 'action' => 'login']); ?>">
    <?php echo __('Log in'); ?>
  </a>
<?php } elseif ($sf_user->isAuthenticated()) { ?>
  <div class="dropdown my-2 ah-user-header-dropdown">
    <button class="ah-user-header-button dropdown-toggle" type="button" id="user-menu" data-bs-toggle="dropdown" aria-expanded="false">
      <?php echo $sf_user->user->username; ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-lg-end mt-2 ah-header-dropdown-menu" aria-labelledby="user-menu">
      <li>
        <h6 class="dropdown-header">
          <?php echo image_tag($gravatar, ['alt' => '']); ?>&nbsp;
          <?php echo __('Hi, %1%', ['%1%' => $sf_user->user->username]); ?>
        </h6>
      </li>
      <li><?php echo link_to(__('My profile'), [$sf_user->user, 'module' => 'user'], ['class' => 'dropdown-item']); ?></li>
      <li>
        <?php if ($sf_context->getConfiguration()->isPluginEnabled('arCasPlugin')) { ?>
          <?php echo link_to(__('Log out'), ['module' => 'cas', 'action' => 'logout'], ['class' => 'dropdown-item']); ?>
        <?php } elseif ($sf_context->getConfiguration()->isPluginEnabled('arOidcPlugin')) { ?>
          <?php echo link_to(__('Log out'), ['module' => 'oidc', 'action' => 'logout'], ['class' => 'dropdown-item']); ?>
        <?php } ?>
      </li>
    </ul>
  </div>
<?php } ?>
