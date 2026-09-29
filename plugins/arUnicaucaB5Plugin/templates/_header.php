<div class="visually-hidden-focusable p-3 border-bottom">
  <a class="btn btn-sm btn-secondary" href="#main-column">
    <?php echo __('Skip to main content'); ?>
  </a>
</div>

<?php echo get_component('default', 'privacyMessage'); ?>

<?php echo get_component('default', 'updateCheck'); ?>

<?php if ($sf_user->isAdministrator() && '' === (string) QubitSetting::getByName('siteBaseUrl')) { ?>
  <div class="alert alert-warning rounded-0 text-center mb-0" role="alert">
    <?php echo link_to(__('Please configure your site base URL'), 'settings/siteInformation', ['class' => 'alert-link']); ?>
  </div>
<?php } ?>

<?php $bgColor = sfConfig::get('app_header_background_colour'); ?>
<?php $darkHeader = 1 === preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', (string) $bgColor, $rgb)
    && 0.299 * hexdec($rgb[1]) + 0.587 * hexdec($rgb[2]) + 0.114 * hexdec($rgb[3]) < 128; ?>
<?php if (!empty($bgColor)) { ?>
  <style <?php echo __(sfConfig::get('csp_nonce')); ?>>
    #top-bar {
      background-color: <?php echo $bgColor; ?> !important;
    }
  </style>
<?php } ?>
<?php $showTitle = sfConfig::get('app_toggleTitle') && !empty(sfConfig::get('app_siteTitle')); ?>
<?php $showDescription = sfConfig::get('app_toggleDescription') && !empty(sfConfig::get('app_siteDescription')); ?>
<header id="top-bar" class="navbar navbar-expand-lg<?php echo $darkHeader ? ' navbar-dark' : ''; ?> d-print-none" role="navigation" aria-label="<?php echo __('Main navigation'); ?>">
  <div class="container-fluid">
    <?php if (sfConfig::get('app_toggleLogo') || $showTitle || $showDescription) { ?>
      <a class="navbar-brand d-flex flex-wrap flex-lg-nowrap align-items-center py-0 me-0" href="<?php echo url_for('@homepage'); ?>" title="<?php echo __('Home'); ?>" rel="home">
        <?php if (file_exists($staticPath = sfConfig::get('app_static_path').DIRECTORY_SEPARATOR.'logo.png')) { ?>
          <?php $logoLoc = sfConfig::get('app_static_alias').'/logo.png'; ?>
          <?php $logoClass = 'uc-brand-logo'; ?>
        <?php } else { ?>
          <?php $logoClass = 'uc-brand-logo uc-brand-logo--institutional'; ?>
          <?php $logoLoc = '/plugins/arUnicaucaB5Plugin/images/logo-universidad-del-cauca-horizontal.png'; ?>
        <?php } ?>
        <?php if (sfConfig::get('app_toggleLogo')) { ?>
          <?php echo image_tag($logoLoc, ['alt' => sfConfig::get('app_siteDescription') ?: sfConfig::get('app_siteTitle'), 'class' => $logoClass.' d-inline-block my-1', 'height' => '68']); ?>
        <?php } ?>
        <?php if ($showTitle || $showDescription) { ?>
          <span class="uc-brand-text d-flex flex-column">
            <?php if ($showTitle) { ?>
              <span class="uc-brand-title text-wrap"><?php echo esc_specialchars(sfConfig::get('app_siteTitle')); ?></span>
            <?php } ?>
            <?php if ($showDescription) { ?>
              <span class="uc-brand-description text-wrap"><?php echo esc_specialchars(sfConfig::get('app_siteDescription')); ?></span>
            <?php } ?>
          </span>
        <?php } ?>
      </a>
    <?php } ?>
    <button class="navbar-toggler atom-btn-secondary my-2 me-1 px-1" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-content" aria-controls="navbar-content" aria-expanded="false">
      <i 
        class="fas fa-2x fa-fw fa-bars" 
        data-bs-toggle="tooltip"
        data-bs-placement="bottom"
        title="<?php echo __('Toggle navigation'); ?>"
        aria-hidden="true">
      </i>
      <span class="visually-hidden"><?php echo __('Toggle navigation'); ?></span>
    </button>
    <div class="collapse navbar-collapse flex-wrap justify-content-end me-1" id="navbar-content">
      <div class="d-flex flex-wrap flex-lg-nowrap flex-grow-1">
        <?php echo get_component('menu', 'browseMenu', ['sf_cache_key' => 'dominion-b5'.$sf_user->getCulture().$sf_user->getUserID()]); ?>
        <?php echo get_component('search', 'box'); ?>
      </div>
      <div class="d-flex flex-nowrap flex-column flex-lg-row align-items-strech align-items-lg-center">
        <ul class="navbar-nav mx-lg-2">
          <?php echo get_component('menu', 'mainMenu', ['sf_cache_key' => 'dominion-b5'.$sf_user->getCulture().$sf_user->getUserID()]); ?>
          <?php echo get_component('menu', 'clipboardMenu'); ?>
          <?php if (sfConfig::get('app_toggleLanguageMenu')) { ?>
            <?php echo get_component('menu', 'changeLanguageMenu'); ?>
          <?php } ?>
          <?php echo get_component('menu', 'quickLinksMenu'); ?>
        </ul>
        <?php echo get_component('menu', 'userMenu'); ?>
      </div>
    </div>
  </div>
</header>
