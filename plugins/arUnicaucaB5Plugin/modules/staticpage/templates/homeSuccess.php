<?php decorate_with('layout_2col'); ?>

<style <?php echo __(sfConfig::get('csp_nonce')); ?>>
  :root {
    --uc-navy: #1f2a5a;
    --uc-lavender: #7d6bb8;
    --uc-red-accent: #c8102e;
    --uc-canvas: #f5f3fa;
    --uc-card: #ffffff;
    --uc-ink: #2d2d3a;
    --uc-font-display: inherit;
  }

  /* Tipografías y Colores */
  .home-eyebrow { font-size: 13px; letter-spacing: 0.2em; color: var(--uc-lavender); }
  .home-title { font-size: 22px; font-family: var(--uc-font-display); color: var(--uc-navy); }
  .home-lead, .home-label { font-size: 15px; font-family: var(--uc-font-display); color: var(--uc-navy); }
  .home-card { background: var(--uc-card); }

  /* Menú lateral */
  .home-menu-title { font-size: 14px; font-family: var(--uc-font-display); color: var(--uc-navy); }
  .home-menu .list-group-item { font-size: 14px; }
  .home-menu .list-group {
    --bs-list-group-active-bg: var(--uc-navy);
    --bs-list-group-active-border-color: var(--uc-navy);
    --bs-list-group-active-color: var(--uc-card);
    --bs-list-group-action-hover-bg: var(--uc-canvas);
    --bs-list-group-action-hover-color: var(--uc-navy);
    --bs-list-group-action-active-bg: var(--uc-canvas);
    --bs-list-group-action-active-color: var(--uc-navy);
    --bs-list-group-color: var(--uc-ink);
  }

  /* Campo de búsqueda y botón */
  .home-search-icon { color: var(--uc-lavender); }
  .home-search-input { font-size: 15px; color: var(--uc-ink); border-color: var(--uc-lavender); }
  .home-search-input:focus {
    border-color: var(--uc-navy);
    box-shadow: 0 0 0 0.2rem rgba(31, 42, 90, 0.25);
  }
  .home-search-btn {
    --bs-btn-font-size: 15px;
    --bs-btn-color: var(--uc-card);
    --bs-btn-bg: var(--uc-navy);
    --bs-btn-border-color: var(--uc-navy);
    --bs-btn-hover-color: var(--uc-card);
    --bs-btn-hover-bg: var(--uc-lavender);
    --bs-btn-hover-border-color: var(--uc-lavender);
    --bs-btn-active-color: var(--uc-card);
    --bs-btn-active-bg: var(--uc-lavender);
    --bs-btn-active-border-color: var(--uc-lavender);
    --bs-btn-focus-shadow-rgb: 31, 42, 90;
  }

  /* Texto de ayuda y enlaces */
  .home-help { border-left-color: var(--uc-red-accent) !important; color: var(--uc-ink); }
  .home-help p { font-size: 14px; }
  .home-help strong { color: var(--uc-navy); }
  .home-advanced-link { color: var(--uc-navy); text-decoration-color: var(--uc-lavender); }
  .home-advanced-link:hover { color: var(--uc-lavender); }
</style>

<?php slot('sidebar'); ?>
  <section class="home-menu card rounded-4 shadow-sm mb-3">
    <h2 class="home-menu-title fw-bold p-3 mb-0"><?php echo __('Opciones de búsqueda'); ?></h2>
    <div class="list-group list-group-flush">
      <a
        class="list-group-item list-group-item-action active"
        aria-current="page"
        href="<?php echo url_for('@homepage'); ?>#search-box-input">
        <i class="fas fa-search me-2" aria-hidden="true"></i>Búsqueda básica
      </a>
      <a
        class="list-group-item list-group-item-action"
        href="<?php echo url_for([
            'module' => 'informationobject',
            'action' => 'browse',
            'showAdvanced' => true,
            'topLod' => false,
        ]); ?>">
        <i class="fas fa-sliders-h me-2" aria-hidden="true"></i>Búsqueda avanzada
      </a>
    </div>
  </section>
<?php end_slot(); ?>

<section class="p-4">

  <div class="mb-4">
    <h1 class="home-title fw-bold lh-sm mb-0">Búsqueda básica</h1>
    <p class="home-lead fw-semibold lh-base mt-2 mb-0 col-lg-10">
      Explore las descripciones archivísticas que preservan nuestra memoria histórica.
    </p>
  </div>

  <div class="home-card rounded-5 shadow-lg p-4">
    <label for="search-box-input" class="home-label d-block fw-bold mb-3">¿Qué desea encontrar?</label>

    <?php /* Caja de búsqueda básica de AtoM; su markup está en modules/search/templates/_box.php del tema. */ ?>
    <?php echo get_component('search', 'box'); ?>

    <div class="home-help border-start border-4 ps-3 mt-4">
      <p class="mb-0 lh-base">
        Ingrese las palabras clave de lo que desea buscar en el campo de texto
        y presione <strong>“Buscar”</strong> para consultar las descripciones archivísticas coincidentes.
        Si prefiere realizar una consulta más específica y detallada, haga clic en la opción
        <a
          class="home-advanced-link fw-bold text-decoration-underline link-offset-2"
          href="<?php echo url_for([
              'module' => 'informationobject',
              'action' => 'browse',
              'showAdvanced' => true,
              'topLod' => false,
          ]); ?>">“Búsqueda avanzada”</a>.
      </p>
    </div>
  </div>

</section>
