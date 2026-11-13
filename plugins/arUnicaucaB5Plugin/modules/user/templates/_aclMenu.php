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

  /* OCULTO-022
     SERVÍA PARA: consultar los permisos del usuario sobre registros de autoridad, instituciones
     archivísticas y taxonomías (pestañas de la sección Permisos de la ficha del usuario).
     MOTIVO: el archivo no maneja registros de autoridad ni instituciones (OCULTO-004 a 016) y no
     gestiona taxonomías desde la interfaz; solo se muestran los permisos sobre descripciones archivísticas.
     Limitación: se ocultan las pestañas; las URL user/indexActorAcl, indexRepositoryAcl e indexTermAcl
     siguen abriendo. Para volver a mostrar una, quita su name de $hiddenAclItems.
     Código original: el foreach de $userAclMenu->getChildren() sin filtro (sigue debajo; solo se
     añadió el `continue` de la lista oculta).
  */
  $hiddenAclItems = ['userActorAcl', 'userRepositoryAcl', 'userTermAcl'];
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

        if (in_array($item->name, $hiddenAclItems, true)) {
            continue;
        }

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
