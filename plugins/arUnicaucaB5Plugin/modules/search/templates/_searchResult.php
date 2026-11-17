<?php
/* KAN-138 · Override de upstream/atom/apps/qubit/modules/search/templates/_searchResult.php (cada resultado de búsqueda).
   Cambios respecto al original (marcados con «KAN-138» abajo; lo demás es idéntico):
   1. El código de referencia (signatura) pasa ENCIMA del título, en su propia línea (clase ah-result-code); se omite si es igual al título.
   2. La fecha lleva la etiqueta «Fechas:» y el productor la etiqueta «Productor:».
   3. Maqueta de Figma «4. Resultados»: icono de la ficha a la izquierda, chevron a la derecha, «Fechas» y «Productor» en una fila
      y la ruta jerárquica al pie (arUnicaucaResultPath), que sustituye a «Parte de».
   4. Los términos buscados se resaltan con <mark class="ah-hit"> en el título y en el extracto (arUnicaucaResultHighlight).
   Los estilos están en scss/_search-results.scss. Este archivo no oculta nada: no requiere ficha en docs/hidden-elements.md. */
$ahTerms = arUnicaucaResultHighlight::terms(sfContext::getInstance()->getRequest()->getParameter('query', ''));
?>
<?php $doc = $hit->getData(); ?>

<?php /* KAN-138: sin objeto digital la ficha lleva icono a la izquierda y chevron a la derecha (fila sin salto de línea). */ ?>
<article class="search-result row g-0 p-3 border-bottom<?php echo empty($doc['hasDigitalObject']) ? ' ah-result-card flex-nowrap' : ''; ?>">
  <?php if (empty($doc['hasDigitalObject'])) { ?>
    <div class="col-auto ah-result-icon" aria-hidden="true"><i class="fas fa-file-alt"></i></div>
  <?php } ?>
  <?php if (!empty($doc['hasDigitalObject'])) { ?>
    <?php
        // Get thumbnail or generic icon path
        if (
            isset($doc['digitalObject']['thumbnailPath'])
            && QubitAcl::check(
                QubitInformationObject::getById($hit->getId()),
                'readThumbnail'
            )
        ) {
            $imagePath = $doc['digitalObject']['thumbnailPath'];
        } else {
            $imagePath = QubitDigitalObject::getGenericIconPathByMediaTypeId(
                $doc['digitalObject']['mediaTypeId'] ?: null
            );
        }
    ?>
    <div class="col-12 col-lg-3 pb-2 pb-lg-0 pe-lg-3">
      <a href="<?php echo url_for(
          ['module' => 'informationobject', 'slug' => $doc['slug']]
      ); ?>">
        <?php echo image_tag($imagePath, [
            'alt' => $doc['digitalObject']['digitalObjectAltText'] ?: strip_markdown(
                get_search_i18n(
                    $doc,
                    'title',
                    ['allowEmpty' => false, 'culture' => $culture]
                )
            ),
            'class' => 'img-thumbnail',
        ]); ?>
      </a>
    </div>
  <?php } ?>

  <div class="<?php echo empty($doc['hasDigitalObject']) ? 'col' : 'col-12 col-lg-9'; ?> d-flex flex-column gap-1">
    <?php /* KAN-138: código de referencia sobre el título (antes iba en la línea de abajo, junto al nivel y la fecha). */ ?>
    <?php
      $ahCode = ('1' == sfConfig::get('app_inherit_code_informationobject', 1) && !empty($doc['referenceCode']))
          ? $doc['referenceCode']
          : ($doc['identifier'] ?? '');
    ?>
    <?php
      // Si el título ya es el código (así se cargan las signaturas del Archivo), no se repite.
      $ahTitleText = trim(strip_tags((string) render_title(get_search_i18n($doc, 'title', ['allowEmpty' => false, 'culture' => $culture]))));
    ?>
    <?php if (!empty($ahCode) && trim((string) $ahCode) !== html_entity_decode($ahTitleText, ENT_QUOTES, 'UTF-8')) { ?>
      <span class="ah-result-code"><?php echo arUnicaucaResultHighlight::mark(htmlspecialchars((string) $ahCode, ENT_QUOTES, 'UTF-8'), $ahTerms); ?></span>
    <?php } ?>
    <div class="d-flex align-items-center gap-2">
      <?php /* KAN-138: el título pasa por el resaltador; el resto de la llamada es el original. */ ?>
      <?php echo link_to(
          arUnicaucaResultHighlight::mark(render_title(get_search_i18n(
              $doc,
              'title',
              ['allowEmpty' => false, 'culture' => $culture]
          )), $ahTerms),
          ['module' => 'informationobject', 'slug' => $doc['slug']],
          ['class' => 'h5 mb-0 text-truncate'],
      ); ?>

      <?php echo get_component('clipboard', 'button', [
          'slug' => $doc['slug'],
          'type' => 'informationObject',
          'wide' => false,
      ]); ?>
    </div>

    <div class="d-flex flex-column gap-2">
      <div class="d-flex flex-column">
        <div class="d-flex flex-wrap column-gap-4 row-gap-1 ah-result-meta">
          <?php $showDash = false; ?>
          <?php /* KAN-138: el código ya se mostró sobre el título; aquí quedan nivel, fechas y estado. */ ?>
          <?php if (
              isset($doc['levelOfDescriptionId'])
              && !empty($doc['levelOfDescriptionId'])
          ) { ?>
            <span class="text-muted">
              <?php echo render_value_inline(
                  QubitCache::getLabel($doc['levelOfDescriptionId'], 'QubitTerm')
              ); ?>
            </span>
            <?php $showDash = true; ?>
          <?php } ?>

          <?php if (isset($doc['dates'])) { ?>
            <?php $date = render_search_result_date($doc['dates']); ?>
            <?php if (!empty($date)) { ?>
              <span class="text-muted">
                <?php /* KAN-138 */ ?><span class="ah-result-label"><?php echo __('Fechas:'); ?></span>
                <?php echo render_value_inline($date); ?>
              </span>
              <?php $showDash = true; ?>
            <?php } ?>
          <?php } ?>

          <?php if (
          isset($doc['creators'])
          && null !== $creationDetails = get_search_creation_details($doc, $culture)
      ) { ?>
        <span class="text-muted">
          <?php /* KAN-138 */ ?><span class="ah-result-label"><?php echo __('Productor:'); ?></span>
          <?php echo render_value_inline($creationDetails); ?>
        </span>
      <?php } ?>

          <?php if (
              isset($doc['publicationStatusId'])
              && QubitTerm::PUBLICATION_STATUS_DRAFT_ID == $doc['publicationStatusId']
          ) { ?>
            <span class="text-muted">
              <?php echo render_value_inline(
                  QubitCache::getLabel($doc['publicationStatusId'], 'QubitTerm')
              ); ?>
            </span>
          <?php } ?>
        </div>

      </div>

      <?php if (null !== $scopeAndContent = get_search_i18n(
          $doc,
          'scopeAndContent',
          ['culture' => $culture]
      )) { ?>
        <span class="text-block d-none">
          <?php /* KAN-138 */ echo arUnicaucaResultHighlight::mark(render_value($scopeAndContent), $ahTerms); ?>
        </span>
      <?php } ?>

      <?php /* KAN-138: ruta jerárquica al pie (sustituye a «Parte de»). */ ?>
      <?php $ahPath = arUnicaucaResultPath::titles($doc['ancestors'] ?? [], $culture); ?>
      <?php if ([] !== $ahPath) { ?>
        <span class="ah-result-path"><span class="visually-hidden"><?php echo __('Ruta:'); ?> </span><?php echo implode(' / ', array_map(function ($t) { return htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); }, $ahPath)); ?></span>
      <?php } ?>
    </div>
  </div>
  <?php if (empty($doc['hasDigitalObject'])) { ?>
    <div class="col-auto ah-result-chevron" aria-hidden="true"><i class="fas fa-chevron-right"></i></div>
  <?php } ?>
</article>
