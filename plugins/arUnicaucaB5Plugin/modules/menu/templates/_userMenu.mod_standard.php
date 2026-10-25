<?php if ($showLogin) { ?>
  <a class="btn btn-sm atom-btn-secondary my-2" href="<?php echo url_for(['module' => 'user', 'action' => 'login']); ?>">
    <?php echo $menuLabels['login']; ?>
  </a>
<?php } elseif ($sf_user->isAuthenticated()) { ?>
  <div class="dropdown my-2">
    <button class="btn btn-sm atom-btn-secondary dropdown-toggle" type="button" id="user-menu" data-bs-toggle="dropdown" aria-expanded="false">
      <?php echo $sf_user->user->username; ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-lg-end mt-2" aria-labelledby="user-menu">
      <li>
        <h6 class="dropdown-header">
          <?php echo image_tag($gravatar, ['alt' => '']); ?>&nbsp;
          <?php echo __('Hi, %1%', ['%1%' => $sf_user->user->username]); ?>
        </h6>
      </li>
      <li><?php echo link_to($menuLabels['myProfile'], [$sf_user->user, 'module' => 'user'], ['class' => 'dropdown-item']); ?></li>
      <li><?php echo link_to($menuLabels['logout'], ['module' => 'user', 'action' => 'logout'], ['class' => 'dropdown-item']); ?></li>
    </ul>
  </div>
<?php } ?>
