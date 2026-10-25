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
<?php if (!empty($bgColor)) { ?>
  <style <?php echo __(sfConfig::get('csp_nonce')); ?>>
    #top-bar {
      background-color: <?php echo $bgColor; ?> !important;
    }
  </style>
<?php } ?>
<header id="top-bar" class="navbar navbar-expand-lg navbar-dark d-print-none ah-site-header" role="navigation" aria-label="<?php echo __('Main navigation'); ?>">
  <div class="container-fluid ah-site-header-inner">
    <a class="navbar-brand ah-site-brand" href="<?php echo url_for('@homepage'); ?>" title="<?php echo __('Home'); ?>" rel="home">
      <span class="ah-site-brand-kicker">Patrimonio documental del Cauca</span>
      <span class="ah-site-brand-name">Archivo Histórico del Cauca</span>
      <span class="ah-site-brand-subtitle">Memoria del Mundo de América Latina y el Caribe</span>
    </a>

    <div class="ah-site-header-tools">
      <div class="ah-site-recognition">
        <span>Reconocimiento</span>
        <strong>Programa Memoria del Mundo - UNESCO</strong>
      </div>
      <?php echo get_component('menu', 'userMenu'); ?>
      <button class="navbar-toggler atom-btn-secondary ah-site-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-content" aria-controls="navbar-content" aria-expanded="false">
        <i class="fas fa-bars" aria-hidden="true"></i>
        <span class="visually-hidden"><?php echo __('Toggle navigation'); ?></span>
      </button>
    </div>

    <div class="collapse navbar-collapse ah-native-navigation" id="navbar-content">
      <div class="d-flex flex-wrap flex-lg-nowrap flex-grow-1">
        <?php echo get_component('menu', 'browseMenu', ['sf_cache_key' => 'dominion-b5'.$sf_user->getCulture().$sf_user->getUserID()]); ?>
      </div>
      <ul class="navbar-nav mx-lg-2">
        <?php echo get_component('menu', 'mainMenu', ['sf_cache_key' => 'dominion-b5'.$sf_user->getCulture().$sf_user->getUserID()]); ?>
        <?php echo get_component('menu', 'clipboardMenu'); ?>
        <?php if (sfConfig::get('app_toggleLanguageMenu')) { ?>
          <?php echo get_component('menu', 'changeLanguageMenu'); ?>
        <?php } ?>
        <?php echo get_component('menu', 'quickLinksMenu'); ?>
      </ul>
    </div>
  </div>
</header>
