<?php $menu = get_component('menu', 'staticPagesMenu'); ?>
<?php $layout = 'layout_1col'; ?>
<?php $isPrivacy = 'privacy' === (string) $resource->slug; ?>
<?php if ($isPrivacy || !empty($menu)) { ?>
  <?php $layout = 'layout_2col'; ?>
  <?php slot('sidebar'); ?>
    <?php if ($isPrivacy) { ?>
      <?php echo get_partial('search/searchOptions', ['active' => 'privacy']); ?>
    <?php } else { ?>
      <?php echo $menu; ?>
    <?php } ?>
  <?php end_slot(); ?>
<?php } ?>
<?php decorate_with($layout); ?>

<?php /* «Acerca de» y «Política de privacidad» pintan su propio título dentro de la cabecera institucional. */ ?>
<?php if (!in_array((string) $resource->slug, ['about', 'privacy'], true)) { ?>
<?php slot('title'); ?>
  <h1><?php echo render_title($resource->getTitle(['cultureFallback' => true])); ?></h1>
<?php end_slot(); ?>
<?php } ?>

<div class="page p-3">

  <div>
    <?php if ('about' === (string) $resource->slug) { ?>
      <?php /* «Acerca de»: el texto sale de la BD (lo escribe el reconcile desde config/atom/content/about-platform.es.md); el diseño lo pone staticpage/_aboutPlatform.php. */ ?>
      <?php echo get_partial('staticpage/aboutPlatform', ['resource' => $resource, 'content' => $sf_data->getRaw('content')]); ?>
    <?php } elseif ('privacy' === (string) $resource->slug) { ?>
      <?php /* «Política de privacidad»: el texto sale de la BD (lo escribe el reconcile desde config/atom/content/privacy-policy.es.md); el diseño lo pone staticpage/_privacyPolicy.php. */ ?>
      <?php echo get_partial('staticpage/privacyPolicy', ['resource' => $resource, 'content' => $sf_data->getRaw('content')]); ?>
    <?php } else { ?>
    <?php echo render_value_html($sf_data->getRaw('content')); ?>
    <?php } ?>
  </div>

</div>

<?php /* OCULTO-003
   SERVÍA PARA: que el administrador pudiera editar y borrar la página estática desde los botones "Editar" y "Borrar" al pie del contenido.
   MOTIVO: no se le ve funcionalidad en el prototipo; el contenido de las páginas estáticas (p. ej. «Acerca de») se maneja desde código.
   Código original:

<?php if (QubitAcl::check($resource, 'update')) { ?>
  <?php slot('after-content'); ?>
    <ul class="actions mb-3 nav gap-2">
      <li><?php echo link_to(__('Edit'), [$resource, 'module' => 'staticpage', 'action' => 'edit'], ['class' => 'btn atom-btn-outline-light']); ?></li>
      <?php if (QubitAcl::check($resource, 'delete')) { ?>
        <li><?php echo link_to(__('Delete'), [$resource, 'module' => 'staticpage', 'action' => 'delete'], ['class' => 'btn atom-btn-outline-danger']); ?></li>
      <?php } ?>
    </ul>
  <?php end_slot(); ?>
<?php } ?>

*/ ?>
