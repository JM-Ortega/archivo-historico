<?php
/**
 * Independent search navigation.
 *
 * @var bool $advancedSearch Whether the advanced search route is active.
 * @var string $navigationTitle Panel heading.
 * @var bool $usersSection Whether the users route is active.
 */
$advancedSearch = isset($advancedSearch) && $advancedSearch;
$navigationTitle = isset($navigationTitle) ? $navigationTitle : __('Navegación');
$usersSection = isset($usersSection) && $usersSection;
$canManageUsers = $sf_user->hasCredential('administrator');
?>
<section class="search-options-navigation card rounded-4 shadow-sm mb-3" aria-labelledby="search-options-title">
  <h2 id="search-options-title" class="home-menu-title fw-bold p-3 mb-0"><?php echo $navigationTitle; ?></h2>
  <div class="list-group list-group-flush">
    <a
      class="list-group-item list-group-item-action<?php echo !$advancedSearch && !$usersSection ? ' active' : ''; ?>"
      href="<?php echo url_for('@homepage'); ?>#search-box-input"
      <?php echo !$advancedSearch && !$usersSection ? 'aria-current="page"' : ''; ?>>
      <i class="fas fa-search me-2" aria-hidden="true"></i>
      Búsqueda básica
    </a>
    <a
      class="list-group-item list-group-item-action<?php echo $advancedSearch ? ' active' : ''; ?>"
      href="<?php echo url_for([
          'module' => 'informationobject',
          'action' => 'browse',
          'showAdvanced' => true,
          'topLod' => false,
      ]); ?>"
      <?php echo $advancedSearch ? 'aria-current="page"' : ''; ?>>
      <i class="fas fa-sliders-h me-2" aria-hidden="true"></i>
      Búsqueda avanzada
    </a>
    <?php if ($canManageUsers) { ?>
      <a
        class="list-group-item list-group-item-action<?php echo $usersSection ? ' active' : ''; ?>"
        href="<?php echo url_for(['module' => 'user', 'action' => 'list']); ?>"
        <?php echo $usersSection ? 'aria-current="page"' : ''; ?>>
        <i class="fas fa-users me-2" aria-hidden="true"></i>
        Usuarios
      </a>
    <?php } ?>
  </div>
</section>
