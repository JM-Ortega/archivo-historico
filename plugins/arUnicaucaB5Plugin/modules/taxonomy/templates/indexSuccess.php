<?php
/*
 * Navegación temática: Navegar → Lugares (taxonomy/index de la taxonomía de lugares).
 *
 * Solo presentación. Conserva el contrato de TaxonomyIndexAction: $pager (consulta, orden y página de AtoM), los parámetros
 * GET `subquery`, `sort`, `sortDir` y `page`, y los enlaces a cada término. El resto de taxonomías (materias, géneros…)
 * siguen usando la vista nativa de AtoM, incluida tal cual al final del fichero.
 *
 * Diseño de referencia: prototipo «Archivo Histórico App Design» (features/places/PlacesBrowser.tsx).
 * Estilos: scss/_places.scss (todo bajo .ah-places). Envío automático de los selects: js/places.js.
 */
?>
<?php if (QubitTaxonomy::PLACE_ID == $resource->id) { ?>
<?php decorate_with('layout_1col'); ?>
<?php
  $culture = $sf_user->getCulture();
  $taxonomyLabel = render_title($resource);
  $route = url_for(['module' => 'taxonomy', 'action' => 'index', 'slug' => $resource->slug]);
  $requestParams = $sf_data->getRaw('sf_request')->getParameterHolder()->getAll();
  $subquery = isset($requestParams['subquery']) && is_string($requestParams['subquery']) ? $requestParams['subquery'] : '';

  $sortOptions = ['alphabetic' => __('Nombre'), 'lastUpdated' => __('Fecha de modificación')];
  $currentSort = isset($sortOptions[$sf_request->sort]) ? $sf_request->sort : 'alphabetic';
  $directionOptions = ['asc' => __('Ascendente'), 'desc' => __('Descendente')];
  $currentDir = 'desc' === $sf_request->sortDir ? 'desc' : 'asc';

  $totalResults = $pager->getNbResults();
  $quickAccess = arUnicaucaPlacesBrowser::quickAccessTerms((int) $resource->id, $culture);

  // Una fila por término de la página actual; los conteos se calculan una sola vez y los usan la tabla y las fichas.
  $rows = [];
  foreach ($pager->getResults() as $hit) {
      $doc = $hit->getData();
      $useFor = [];
      if (isset($doc['useFor'])) {
          foreach ($doc['useFor'] as $label) {
              $useFor[] = render_value_inline(get_search_i18n($label, 'name', ['allowEmpty' => false]));
          }
      }
      $notes = [];
      if (isset($doc['scopeNotes'])) {
          foreach ($doc['scopeNotes'] as $note) {
              $notes[] = render_value_inline(get_search_i18n($note, 'content'));
          }
      }
      $rows[] = [
          'name' => render_title(get_search_i18n($doc, 'name', ['allowEmpty' => false])),
          'slug' => $doc['slug'],
          'isProtected' => !empty($doc['isProtected']),
          'descendants' => isset($doc['numberOfDescendants']) ? (int) $doc['numberOfDescendants'] : 0,
          'useFor' => $useFor,
          'notes' => $notes,
          'descriptions' => QubitTerm::countRelatedInformationObjects($hit->getId()),
          'authorities' => TermNavigateRelatedComponent::getEsDocsRelatedToTermCount('QubitActor', $hit->getId()),
      ];
  }
?>

<?php slot('content'); ?>
<section class="ah-places" aria-labelledby="ah-places-title">

  <header class="ah-places-header">
    <span class="ah-places-header-icon" aria-hidden="true">
      <i class="fas fa-map-marker-alt"></i>
    </span>
    <div>
      <p class="ah-places-kicker"><?php echo __('Navegación temática'); ?></p>
      <h1 id="ah-places-title" class="ah-places-title" aria-describedby="ah-places-label">
        <?php echo __('Mostrando %1% resultados', ['%1%' => $totalResults]); ?>
      </h1>
      <p id="ah-places-label" class="ah-places-label"><?php echo $taxonomyLabel; ?></p>
    </div>
  </header>

  <div class="ah-places-layout">

    <aside class="ah-places-panel ah-places-quick" aria-labelledby="ah-places-quick-title">
      <div class="ah-places-quick-header">
        <h2 id="ah-places-quick-title" class="ah-places-quick-title"><?php echo __('Navegar lugares'); ?></h2>
        <p class="ah-places-quick-text"><?php echo __('Acceso rápido a los lugares presentes en las fichas.'); ?></p>
      </div>
      <?php if (count($quickAccess)) { ?>
        <nav class="ah-places-quick-list" aria-label="<?php echo __('Acceso rápido por lugares'); ?>">
          <?php foreach ($quickAccess as $place) { ?>
            <a class="ah-places-quick-item" href="<?php echo url_for(['module' => 'term', 'slug' => $place['slug']]); ?>">
              <span class="ah-places-quick-name"><?php echo esc_entities($place['name']); ?></span>
              <span class="ah-places-count" aria-label="<?php echo __('%1% descripciones', ['%1%' => $place['count']]); ?>"><?php echo $place['count']; ?></span>
            </a>
          <?php } ?>
        </nav>
      <?php } else { ?>
        <p class="ah-places-quick-empty"><?php echo __('No hay lugares registrados.'); ?></p>
      <?php } ?>
    </aside>

    <div class="ah-places-main">

      <form class="ah-places-panel ah-places-toolbar" method="get" action="<?php echo $route; ?>" role="search" aria-label="<?php echo __('Buscar lugares'); ?>" data-ah-places-filters>
        <label class="ah-places-search">
          <span class="visually-hidden"><?php echo __('Buscar lugares'); ?></span>
          <i class="fas fa-search ah-places-search-icon" aria-hidden="true"></i>
          <input
            class="ah-places-field ah-places-search-input"
            type="search"
            name="subquery"
            value="<?php echo esc_entities($subquery); ?>"
            placeholder="<?php echo __('Buscar lugares'); ?>">
        </label>

        <label class="ah-places-sort">
          <span class="ah-places-sort-label"><?php echo __('Ordenar por'); ?></span>
          <select class="ah-places-field ah-places-select" name="sort" data-ah-autosubmit>
            <?php foreach ($sortOptions as $value => $label) { ?>
              <option value="<?php echo $value; ?>"<?php echo $currentSort === $value ? ' selected' : ''; ?>><?php echo $label; ?></option>
            <?php } ?>
          </select>
        </label>

        <label class="ah-places-sort">
          <span class="ah-places-sort-label"><?php echo __('Dirección'); ?></span>
          <select class="ah-places-field ah-places-select" name="sortDir" data-ah-autosubmit>
            <?php foreach ($directionOptions as $value => $label) { ?>
              <option value="<?php echo $value; ?>"<?php echo $currentDir === $value ? ' selected' : ''; ?>><?php echo $label; ?></option>
            <?php } ?>
          </select>
        </label>

        <button type="submit" class="visually-hidden"><?php echo __('Buscar'); ?></button>
      </form>

      <?php if ($totalResults > 0) { ?>
        <div class="ah-places-panel ah-places-results">

          <?php // Pantallas estrechas: una ficha por lugar. ?>
          <div class="ah-places-cards">
            <?php foreach ($rows as $row) { ?>
              <article class="ah-places-card">
                <?php echo link_to($row['name'], ['module' => 'term', 'slug' => $row['slug']], ['class' => 'ah-places-term']); ?>
                <dl class="ah-places-card-data">
                  <div class="ah-places-card-wide">
                    <dt><?php echo __('Nota sobre el alcance'); ?></dt>
                    <dd class="ah-places-note">
                      <?php if (count($row['notes'])) { ?>
                        <?php foreach ($row['notes'] as $note) { ?>
                          <span class="d-block"><?php echo $note; ?></span>
                        <?php } ?>
                      <?php } else { ?>
                        <?php echo __('Sin nota registrada'); ?>
                      <?php } ?>
                    </dd>
                  </div>
                  <div>
                    <dt><?php echo __('Descripciones'); ?></dt>
                    <dd class="ah-places-number"><?php echo $row['descriptions']; ?></dd>
                  </div>
                  <div>
                    <dt><?php echo __('Autoridades'); ?></dt>
                    <dd class="ah-places-number"><?php echo $row['authorities']; ?></dd>
                  </div>
                </dl>
              </article>
            <?php } ?>
          </div>

          <?php // Pantallas anchas: tabla. ?>
          <div class="ah-places-table-wrap">
            <table class="ah-places-table">
              <thead>
                <tr>
                  <th scope="col" class="ah-places-col-term"><?php echo __('Término %1%', ['%1%' => mb_strtolower(strip_tags($taxonomyLabel), 'UTF-8')]); ?></th>
                  <th scope="col" class="ah-places-col-note"><?php echo __('Nota sobre el alcance'); ?></th>
                  <th scope="col" class="ah-places-col-count"><?php echo __('Descripciones archivísticas'); ?></th>
                  <th scope="col" class="ah-places-col-count"><?php echo __('Registros de autoridad'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row) { ?>
                  <tr>
                    <td>
                      <?php echo link_to($row['name'], ['module' => 'term', 'slug' => $row['slug']], ['class' => 'ah-places-term'.($row['isProtected'] ? ' readOnly' : '')]); ?>
                      <?php if (0 < $row['descendants']) { ?>
                        <span class="ah-places-descendants">(<?php echo $row['descendants']; ?>)</span>
                      <?php } ?>
                      <?php if (count($row['useFor'])) { ?>
                        <p class="ah-places-usefor"><?php echo __('Use for: ').implode(', ', $row['useFor']); ?></p>
                      <?php } ?>
                    </td>
                    <td class="ah-places-note">
                      <?php if (count($row['notes'])) { ?>
                        <?php foreach ($row['notes'] as $note) { ?>
                          <span class="d-block"><?php echo $note; ?></span>
                        <?php } ?>
                      <?php } else { ?>
                        &mdash;
                      <?php } ?>
                    </td>
                    <td class="ah-places-number"><?php echo $row['descriptions']; ?></td>
                    <td class="ah-places-number"><?php echo $row['authorities']; ?></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="ah-places-pager">
          <p class="ah-places-pager-count">
            <?php echo __('Resultados %1% a %2% de %3%', [
                '%1%' => $pager->getFirstIndice(),
                '%2%' => $pager->getLastIndice(),
                '%3%' => $totalResults,
            ]); ?>
          </p>
          <?php if ($pager->haveToPaginate()) { ?>
            <?php $page = (int) $pager->getPage(); ?>
            <?php $lastPage = (int) $pager->getLastPage(); ?>
            <nav class="ah-places-pagination" aria-label="<?php echo __('Paginación de lugares'); ?>">
              <?php if ($page > 1) { ?>
                <a class="ah-places-page" href="<?php echo url_for(['page' => $page - 1] + $requestParams); ?>"><?php echo __('Anterior'); ?></a>
              <?php } else { ?>
                <span class="ah-places-page is-disabled" aria-disabled="true"><?php echo __('Anterior'); ?></span>
              <?php } ?>

              <?php foreach (arUnicaucaPlacesBrowser::pageLinks($page, $lastPage) as $number) { ?>
                <?php if ($number === $page) { ?>
                  <span class="ah-places-page is-active" aria-current="page"><?php echo $number; ?></span>
                <?php } else { ?>
                  <a class="ah-places-page" href="<?php echo url_for(['page' => $number] + $requestParams); ?>" title="<?php echo __('Ir a la página %1%', ['%1%' => $number]); ?>"><?php echo $number; ?></a>
                <?php } ?>
              <?php } ?>

              <?php if ($page < $lastPage) { ?>
                <a class="ah-places-page" href="<?php echo url_for(['page' => $page + 1] + $requestParams); ?>"><?php echo __('Siguiente'); ?></a>
              <?php } else { ?>
                <span class="ah-places-page is-disabled" aria-disabled="true"><?php echo __('Siguiente'); ?></span>
              <?php } ?>
            </nav>
          <?php } ?>
        </div>
      <?php } else { ?>
        <div class="ah-places-panel ah-places-empty">
          <i class="fas fa-map-marker-alt ah-places-empty-icon" aria-hidden="true"></i>
          <h2 class="ah-places-empty-title"><?php echo __('No se encontraron lugares'); ?></h2>
          <p class="ah-places-empty-text"><?php echo __('Pruebe con otro nombre o elimine el término de búsqueda.'); ?></p>
          <a class="ah-places-empty-action" href="<?php echo $route; ?>"><?php echo __('Mostrar todos'); ?></a>
        </div>
      <?php } ?>

      <?php /* OCULTO-017
         SERVÍA PARA: crear un lugar nuevo (término de la taxonomía Lugares) desde Navegar → Lugares.
         MOTIVO: fuera de alcance; el prototipo no gestiona el vocabulario de lugares desde la interfaz.
         Código original (upstream/atom/apps/qubit/modules/taxonomy/templates/indexSuccess.php, slot after-content):
      <?php if (QubitAcl::check($resource, 'createTerm')) { ?>
        <section class="actions ah-places-actions">
          <?php echo link_to(__('Add new'), ['module' => 'term', 'action' => 'add', 'taxonomy' => url_for([$resource, 'module' => 'taxonomy'])], ['class' => 'btn atom-btn-outline-light']); ?>
        </section>
      <?php } ?>
      */ ?>
    </div>
  </div>
</section>
<?php end_slot(); ?>
<?php } else { ?>
<?php // Materias, géneros y demás taxonomías: vista nativa de AtoM sin cambios. ?>
<?php include sfConfig::get('sf_app_module_dir').'/taxonomy/templates/indexSuccess.php'; ?>
<?php } ?>
