<?php if ($sf_context->getConfiguration()->isPluginEnabled('arCasPlugin') || $sf_context->getConfiguration()->isPluginEnabled('arOidcPlugin')) { ?>
  <?php include sfConfig::get('sf_app_module_dir').'/user/templates/indexSuccess.mod_ext_auth.php'; ?>
<?php } else { ?>
  <?php include __DIR__.'/indexSuccess.mod_standard.php'; ?>
<?php } ?>
