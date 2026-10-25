<?php decorate_with('layout_2col'); ?>

<?php // Estilos: scss/_home.scss (.ah-home-*) y scss/_search.scss. Todo sale del bundle del theme. ?>

<?php slot('sidebar'); ?>
  <?php echo get_partial('search/searchOptions', ['active' => 'basic']); ?>
<?php end_slot(); ?>

<?php $advancedSearchUrl = url_for([
    'module' => 'informationobject',
    'action' => 'browse',
    'showAdvanced' => true,
    'topLod' => false,
]); ?>

<section class="p-4">

  <div class="ah-home-hero mb-4">
    <div class="ah-home-hero__grid">
      <div class="ah-home-hero__main">
        <img
          class="ah-home-hero__img"
          src="<?php echo public_path('plugins/arUnicaucaB5Plugin/images/archivo-volumenes.jpg'); ?>"
          width="800"
          height="600"
          fetchpriority="high"
          decoding="async"
          alt="Volúmenes documentales conservados en el archivo">
        <div class="ah-home-hero__overlay" aria-hidden="true"></div>
        <div class="ah-home-hero__text">
          <p class="ah-home-hero__eyebrow">Consulta del archivo</p>
          <h1 class="ah-home-hero__title">Búsqueda básica</h1>
          <p class="ah-home-hero__lead">
            Explore las descripciones archivísticas que preservan nuestra memoria histórica.
          </p>
        </div>
      </div>

      <figure class="ah-home-hero__side">
        <img
          class="ah-home-hero__img"
          src="<?php echo public_path('plugins/arUnicaucaB5Plugin/images/archivo-patio.jpg'); ?>"
          width="800"
          height="600"
          loading="lazy"
          decoding="async"
          alt="Patio del Archivo Histórico del Cauca">
        <div class="ah-home-hero__side-overlay" aria-hidden="true"></div>
        <figcaption class="ah-home-hero__caption">Archivo Histórico del Cauca</figcaption>
        <div class="ah-home-hero__corner" aria-hidden="true"></div>
      </figure>
    </div>
  </div>

  <div class="ah-home-card">
    <div class="ah-home-card__head">
      <span class="ah-home-card__icon"><i class="fas fa-search" aria-hidden="true"></i></span>
      <div>
        <p class="ah-home-card__title">Consultar descripciones</p>
        <p class="ah-home-card__subtitle">Ingrese una palabra, nombre, lugar o tema relacionado con el documento.</p>
      </div>
    </div>

    <label for="search-box-input" class="ah-home-card__label">¿Qué desea encontrar?</label>

    <?php /* OCULTO-001
      SIRVE PARA: buscar texto en todas las descripciones desde cualquier página.
      MOTIVO: la búsqueda básica se reubicó en la portada.
    */ ?>
    <?php /* Caja de búsqueda básica de AtoM; su markup está en modules/search/templates/_box.php del tema. */ ?>
    <?php echo get_component('search', 'box'); ?>

    <div class="ah-home-help">
      <p>
        Ingrese las palabras clave de lo que desea buscar en el campo
        <strong>“Búsqueda básica”</strong> y presione <strong>“Buscar”</strong>
        para consultar las descripciones archivísticas coincidentes. Si prefiere
        realizar una consulta más específica y detallada, haga clic en la opción del menú
        <a class="ah-home-help__link" href="<?php echo $advancedSearchUrl; ?>">“Búsqueda avanzada”</a>.
      </p>
    </div>
  </div>

</section>
