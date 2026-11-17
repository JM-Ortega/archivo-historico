<?php
/*
 * Diseño de la «Política de privacidad» (página estática «privacy»). Diseño Figma «Privacy Policy».
 * Estilos: scss/_privacy.scss (.ah-privacy*).
 * El texto NO está aquí: lo escribe el reconcile en la BD desde config/atom/content/privacy-policy.es.md
 * (config/atom/reconcile-privacy-policy.php) y llega a este partial en $content (Markdown).
 * Cada encabezado «## » del Markdown se pinta como una sección numerada (01, 02…); el texto que haya antes del primer
 * encabezado se muestra como entradilla del panel. Si el contenido no tiene encabezados, se muestra entero sin numerar.
 * Variables: $resource (página estática «privacy») y $content.
 */

$markdown = str_replace("\r\n", "\n", (string) $sf_data->getRaw('content'));
$parts = preg_split('/^##[ \t]+(.+?)[ \t]*$/m', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
$intro = trim((string) array_shift($parts));
$sections = [];
for ($i = 0; $i + 1 < count($parts); $i += 2) {
    $sections[] = ['title' => trim($parts[$i]), 'body' => trim($parts[$i + 1])];
}
?>
<article class="ah-privacy">
  <header class="ah-privacy__header">
    <div class="ah-privacy__grid" aria-hidden="true"></div>
    <div class="ah-privacy__header-inner">
      <span class="ah-privacy__icon">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
          <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="1.6"/>
          <path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        </svg>
      </span>
      <div>
        <p class="ah-privacy__eyebrow">Información institucional</p>
        <h1 class="ah-privacy__title"><?php echo render_title($resource->getTitle(['cultureFallback' => true])); ?></h1>
      </div>
    </div>
  </header>

  <section class="ah-privacy__panel">
    <div class="ah-privacy__panel-head">
      <?php if ('' !== $intro) { ?>
        <div class="ah-privacy__lead"><?php echo render_value_html($intro); ?></div>
      <?php } else { ?>
        <p class="ah-privacy__lead">Información sobre el tratamiento de datos, el uso de cookies y las condiciones de privacidad aplicables al sistema.</p>
      <?php } ?>
    </div>

    <?php if ([] === $sections) { ?>
      <div class="ah-privacy__list">
        <div class="ah-privacy__section ah-privacy__section--plain">
          <div class="ah-privacy__text"><?php echo render_value_html($markdown); ?></div>
        </div>
      </div>
    <?php } else { ?>
      <div class="ah-privacy__list">
        <?php foreach ($sections as $index => $section) { ?>
          <section class="ah-privacy__section">
            <span class="ah-privacy__number" aria-hidden="true"><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
            <div>
              <h2 class="ah-privacy__section-title"><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
              <?php if ('' !== $section['body']) { ?>
                <div class="ah-privacy__text"><?php echo render_value_html($section['body']); ?></div>
              <?php } ?>
            </div>
          </section>
        <?php } ?>
      </div>
    <?php } ?>
  </section>
</article>
