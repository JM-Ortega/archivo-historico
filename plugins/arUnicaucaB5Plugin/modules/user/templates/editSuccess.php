<?php if ($sf_context->getConfiguration()->isPluginEnabled('arCasPlugin') || $sf_context->getConfiguration()->isPluginEnabled('arOidcPlugin')) { ?>
  <?php include sfConfig::get('sf_app_module_dir').'/user/templates/editSuccess.mod_ext_auth.php'; ?>
<?php } else { ?>
  <?php include __DIR__.'/editSuccess.mod_standard.php'; ?>
<?php } ?>
