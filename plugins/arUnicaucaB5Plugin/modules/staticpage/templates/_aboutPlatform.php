<?php
/*
 * Diseño de «Acerca de la plataforma» (página estática «about»).
 * Estilos: scss/_about.scss (.ah-about*).
 * El texto NO está aquí: lo escribe el reconcile en la BD desde config/atom/content/about-platform.es.md
 * (config/atom/reconcile-about.php) y llega a este partial en $content (Markdown).
 * Variables: $resource (página estática «about») y $content.
 */
?>
<article class="ah-about">
  <header class="ah-about__header">
    <p class="ah-about__eyebrow">Información institucional</p>
    <h1 class="ah-about__title"><?php echo render_title($resource->getTitle(['cultureFallback' => true])); ?></h1>
  </header>

  <section class="ah-about__panel">
    <div class="ah-about__panel-head">
      <p class="ah-about__kicker">Archivo Histórico del Cauca</p>
    </div>
    <div class="ah-about__body">
      <?php echo render_value_html($sf_data->getRaw('content')); ?>
    </div>
  </section>
</article>
