<?php
/**
 * Shared navigation for the public search area.
 *
 * @var string $active Active item: basic, advanced, cataloging, export, history, users or privacy.
 */
$module = $sf_context->getModuleName();
$action = $sf_context->getActionName();
$active = isset($active) ? (string) $active : '';
$isAdvanced = 'advanced' === $active || ('informationobject' === $module && 'browse' === $action && filter_var($sf_request->getParameter('showAdvanced'), FILTER_VALIDATE_BOOLEAN));
$isCataloging = 'cataloging' === $active || ('informationobject' === $module && in_array($action, ['add', 'edit'], true));
$isUsers = 'users' === $active || ('user' === $module && 'list' === $action);
$isAdministrator = $sf_user->isAdministrator();
$canCatalog = $isAdministrator || $sf_user->hasCredential('editor');
$privacyUrl = url_for(['slug' => 'privacy']);
?>
<section class="ah-search-navigation" aria-labelledby="search-navigation-title">
  <h2 class="ah-search-navigation__title" id="search-navigation-title">NAVEGACIÓN</h2>
  <nav aria-label="<?php echo __('Navegación'); ?>">
    <div class="ah-search-navigation__items">
      <a
        class="ah-search-navigation__item<?php echo !$isAdvanced && !$isUsers && ('' === $active || 'basic' === $active) ? ' is-active' : ''; ?>"
        href="<?php echo url_for('@homepage'); ?>#search-box-input"
        <?php echo !$isAdvanced && !$isUsers && ('' === $active || 'basic' === $active) ? 'aria-current="page"' : ''; ?>>
        <i class="fas fa-search" aria-hidden="true"></i>
        <span>Búsqueda básica</span>
      </a>
      <a
        class="ah-search-navigation__item<?php echo $isAdvanced ? ' is-active' : ''; ?>"
        href="<?php echo url_for([
            'module' => 'informationobject',
            'action' => 'browse',
            'showAdvanced' => true,
            'topLod' => false,
        ]); ?>"
        <?php echo $isAdvanced ? 'aria-current="page"' : ''; ?>>
        <i class="fas fa-sliders-h" aria-hidden="true"></i>
        <span>Búsqueda avanzada</span>
      </a>
      <?php if ($isAdministrator) { ?>
        <a class="ah-search-navigation__item<?php echo 'export' === $active ? ' is-active' : ''; ?>" href="<?php echo url_for(['module' => 'jobs', 'action' => 'browse']); ?>">
          <i class="fas fa-download" aria-hidden="true"></i>
          <span>Exportación</span>
        </a>
        <a class="ah-search-navigation__item<?php echo 'history' === $active ? ' is-active' : ''; ?>" href="<?php echo url_for(['module' => 'search', 'action' => 'descriptionUpdates']); ?>">
          <i class="far fa-clock" aria-hidden="true"></i>
          <span>Historial</span>
        </a>
        <a
          class="ah-search-navigation__item<?php echo $isUsers ? ' is-active' : ''; ?>"
          href="<?php echo url_for(['module' => 'user', 'action' => 'list']); ?>"
          <?php echo $isUsers ? 'aria-current="page"' : ''; ?>>
          <i class="far fa-user" aria-hidden="true"></i>
          <span>Listar Usuarios</span>
        </a>
      <?php } ?>
      <?php if ($canCatalog) { ?>
        <a
          class="ah-search-navigation__item<?php echo $isCataloging ? ' is-active' : ''; ?>"
          href="<?php echo url_for(['module' => 'informationobject', 'action' => 'add']); ?>"
          <?php echo $isCataloging ? 'aria-current="page"' : ''; ?>>
          <i class="fas fa-edit" aria-hidden="true"></i>
          <span>Catalogación</span>
        </a>
      <?php } ?>
      <a class="ah-search-navigation__item<?php echo 'privacy' === $active ? ' is-active' : ''; ?>" href="<?php echo $privacyUrl; ?>">
        <i class="fas fa-lock" aria-hidden="true"></i>
        <span>Política de privacidad</span>
      </a>
    </div>
  </nav>
  <div class="ah-search-navigation__footer">
    <h3>Memoria abierta</h3>
    <p>Patrimonio documental al servicio de la ciudadanía y la investigación.</p>
  </div>
</section>
