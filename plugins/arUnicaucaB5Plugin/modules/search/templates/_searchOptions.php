<?php
/*
 * Menú «Opciones de búsqueda» (búsqueda básica / avanzada). Lo usan la portada (staticpage/home) y el listado de resultados
 * (informationobject/browse). Estilos: scss/_search.scss (.ah-search-options).
 *
 * Parámetro: $active = 'basic' (por defecto) | 'advanced'.
 */
$isAdvanced = isset($active) && 'advanced' === (string) $active;
?>
<section class="ah-search-options" aria-labelledby="search-options-title">
  <h2 class="ah-search-options__title" id="search-options-title"><?php echo __('Opciones de búsqueda'); ?></h2>
  <div class="list-group list-group-flush">
    <a
      class="list-group-item list-group-item-action<?php echo $isAdvanced ? '' : ' active'; ?>"
      href="<?php echo url_for('@homepage'); ?>#search-box-input"
      <?php echo $isAdvanced ? '' : 'aria-current="page"'; ?>>
      <i class="fas fa-search me-2" aria-hidden="true"></i>Búsqueda básica
    </a>
    <a
      class="list-group-item list-group-item-action<?php echo $isAdvanced ? ' active' : ''; ?>"
      href="<?php echo url_for([
          'module' => 'informationobject',
          'action' => 'browse',
          'showAdvanced' => true,
          'topLod' => false,
      ]); ?>"
      <?php echo $isAdvanced ? 'aria-current="page"' : ''; ?>>
      <i class="fas fa-sliders-h me-2" aria-hidden="true"></i>Búsqueda avanzada
    </a>
  </div>
</section>
