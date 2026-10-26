<?php
  // Categorías de permisos: ítems del menú nativo "users" (AtoM), con el vocabulario del Archivo.
  // $hideActive omite el ítem de la vista actual (en el detalle, el perfil, que ya es la página en la que se está).
  $labels = [
      'userProfile' => 'Perfil',
      'userInformationObjectAcl' => 'Descripciones archivísticas',
      'userActorAcl' => 'Registros de autoridad',
      'userRepositoryAcl' => 'Instituciones archivísticas',
      'userTermAcl' => 'Taxonomías',
  ];
  $hideActive = !empty($hideActive);
?>
<nav class="ah-user-tabs-nav" aria-label="<?php echo __('Categorías de permisos'); ?>">
  <ul class="ah-user-tabs">
    <?php foreach ($userAclMenu->getChildren() as $item) { ?>
      <?php
        $options = ['class' => 'ah-user-tab'];
        $isCurrent = str_replace(
            '%currentSlug%',
            $sf_request->getAttribute('sf_route')->resource->slug,
            $item->path
        ) == $sf_context->getRouting()->getCurrentInternalUri();

        if ($isCurrent && $hideActive) {
            continue;
        }

        if ($isCurrent) {
            $options['class'] .= ' active';
            $options['aria-current'] = 'page';
        }
      ?>
      <li>
        <?php echo link_to(
            isset($labels[$item->name]) ? __($labels[$item->name]) : $item->getLabel(['cultureFallback' => true]),
            $item->getPath(['getUrl' => true, 'resolveAlias' => true]),
            $options
        ); ?>
      </li>
    <?php } ?>
  </ul>
</nav>
