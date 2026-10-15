<?php
/*
 * Formulario de creación/edición de descripciones archivísticas (ISAD(G)) del Archivo Histórico.
 *
 * Override de plugins/sfIsadPlugin/modules/sfIsadPlugin/templates/editSuccess.php (AtoM 2.10.2). Los campos de la ficha
 * cotidiana van primero, con el vocabulario del Archivo; el resto de campos ISAD(G) nativos quedan en "campos adicionales".
 *
 * Contrato de preservación: TODOS los controles nativos siguen dentro del mismo <form> y se envían siempre (la sección
 * adicional solo se colapsa con CSS, nunca se deshabilita ni se omite). DefaultEditAction::processForm procesa cada campo
 * del formulario, así que un control que faltara en el POST vaciaría su dato. Cada campo se renderiza una sola vez.
 *
 * Ficha adaptable (CAT-03): el nivel elegido (cabecera) fija un perfil —agrupación, unidad documental o general— que solo
 * decide qué campos se destacan (`data-ah-primary`) y cómo se llaman (`data-ah-labels`). Cada control tiene una posición
 * fija y nunca se mueve ni se deshabilita: un campo secundario se oculta únicamente con CSS (css/_cataloging-form.scss) y
 * se sigue enviando. Es visible si el perfil lo prioriza, si trae datos o un error del servidor (`data-ah-keep`), si el
 * usuario lo usó o lo reveló. La ocultación solo rige cuando js/cataloging-form.js marca la raíz con `.ah-js` al arrancar:
 * sin JavaScript (o con el bundle caído) no hay ocultación ni estado inicial, y es el formulario general completo.
 */
?>
<?php decorate_with('layout_2col.php'); ?>
<?php use_helper('Date', 'UcHelp'); ?>

<?php slot('sidebar'); ?>

  <?php include_component('repository', 'contextMenu'); ?>

<?php end_slot(); ?>

<?php slot('title'); ?>
  <?php echo get_component('informationobject', 'descriptionHeader', ['resource' => $resource, 'title' => (string) $isad]); ?>

  <?php if (isset($sf_request->source)) { ?>
    <div class="alert alert-info" role="alert">
      <?php echo __('This is a duplicate of record %1%', ['%1%' => $sourceInformationObjectLabel]); ?>
    </div>
  <?php } ?>
<?php end_slot(); ?>

<?php slot('content'); ?>

<?php
    // Campos nativos que viven en "campos adicionales": abren la sección si traen un error y cuentan como "con datos".
    $additionalFields = [
        'repository', 'archivalHistory', 'acquisition', 'appraisal', 'accruals', 'arrangement',
        'accessConditions', 'reproductionConditions', 'language', 'script', 'languageNotes', 'physicalCharacteristics',
        'findingAids', 'locationOfOriginals', 'locationOfCopies', 'relatedUnitsOfDescription', 'relatedMaterialDescriptions',
        'subjectAccessPoints', 'genreAccessPoints', 'nameAccessPoints', 'descriptionIdentifier',
        'institutionResponsibleIdentifier', 'rules', 'descriptionStatus', 'descriptionDetail', 'revisionHistory',
        'languageOfDescription', 'scriptOfDescription', 'sources', 'displayStandard',
    ];
    $additionalOpen = false;
    $additionalFilled = 0;
    foreach ($additionalFields as $name) {
        $field = $sf_data->getRaw('form')[$name];
        $additionalOpen = $additionalOpen || $field->hasError();
        $value = $field->getValue();
        $widget = $field->getWidget();
        if ($widget instanceof sfWidgetFormSelect && !$widget->getOption('multiple')) {
            // Los selects nativos llevan por defecto una URL aunque no haya término: solo cuenta una opción real.
            $filled = 0 < strlen((string) $value) && array_key_exists((string) $value, $widget->getChoices());
        } else {
            $filled = is_array($value) ? 0 < count(array_filter($value, 'strlen')) : 0 < strlen((string) $value);
        }
        if ($filled) {
            ++$additionalFilled;
        }
    }
    foreach (['publicationNotesComponent', 'archivistsNotesComponent'] as $component) {
        $additionalFilled += count($sf_data->getRaw($component)->getVar('notes'));
    }
    $additionalFilled += count($sf_data->getRaw('alternativeIdentifiersComponent')->getVar('alternativeIdentifiers'));

    // Autocomplete nativo de actores (sin data-link-existing: un nombre nuevo siempre crea su propio actor).
    $actorInputs = '<input class="list" type="hidden" value="'
        .url_for(['module' => 'actor', 'action' => 'autocomplete', 'showOnlyActors' => 'true'])
        .'">';
    if (QubitAcl::check(QubitActor::getRoot(), 'create')) {
        $actorInputs .= '<input class="add" type="hidden" value="'
            .url_for(['module' => 'actor', 'action' => 'add'])
            .' #authorizedFormOfName">';
    }
    // Campo de la ficha cotidiana: etiqueta + control nativos (render_field), con ayuda contextual opcional (uc_help) junto a la
    // etiqueta. El id de la ayuda termina en `-tip`, no en `-help`: Dominion oculta las `-help` en edición y las convierte en
    // un tooltip propio al enfocar el control (tooltip.js); aquí la ayuda la da el botón de información, una sola vez.
    $dailyField = static function ($field, string $label, ?string $help = null, $resource = null, array $options = []): string {
        $id = $field->getName().'-tip';
        if (null !== $help) {
            $options['aria-describedby'] = $id;
        }
        $html = render_field($field->label($label)->help(''), $resource, $options);
        if (null === $help) {
            return $html;
        }

        return preg_replace('#</label>#', '</label>'.uc_help($id, $label, $help), $html, 1);
    };
    $actorHelp = __('Selecciona un nombre de la lista o escribe uno nuevo. Puedes agregar varios.');

    // --- Ficha adaptable: estado y perfil --------------------------------------------------------------------------------
    $isEdit = isset($sf_request->getAttribute('sf_route')->resource);
    $isDuplicate = !$isEdit && isset($sf_request->source);
    // Alta limpia: la ficha espera a que se elija un nivel (o "Describir sin nivel"). Edición, duplicado y POST inválido
    // arrancan ya con el perfil de su nivel.
    $initial = !$isEdit && !$isDuplicate && !$sf_request->isMethod('post');
    $levelProfiles = $sf_data->getRaw('levelProfiles');
    $levelValue = (string) $form->levelOfDescription->getValue();
    $profile = $initial ? 'general' : ($levelProfiles[$levelValue] ?? 'general');
    $profileTitles = ['agrupacion' => __('Agrupación documental'), 'unidad' => __('Unidad documental'), 'general' => __('Ficha general')];

    $hasValue = static function ($field): bool {
        $value = $field->getValue();

        return is_array($value) ? 0 < count(array_filter($value, 'strlen')) : 0 < strlen((string) $value);
    };
    // Atributos de un campo conmutable: perfiles que lo priorizan + "con datos o error" decidido por el servidor.
    $switchAttrs = static function (string $key, string $primary, $field) use ($hasValue): string {
        $keep = $field->hasError() || $hasValue($field);

        return ' data-ah-field="'.$key.'" data-ah-primary="'.$primary.'"'.($keep ? ' data-ah-keep="1"' : '');
    };
    // Etiqueta según el perfil actual (el JS las cambia con el nivel). General usa vocabulario neutral.
    $labelFor = static fn (array $map): string => $map[$profile] ?? $map['general'];
    $labelAttr = static fn (array $map): string => ' data-ah-labels="'.htmlspecialchars(json_encode($map, JSON_UNESCAPED_UNICODE), ENT_QUOTES).'"';
    $labels = [
        'identifier' => ['unidad' => __('Signatura'), 'agrupacion' => __('Identificador'), 'general' => __('Identificador')],
        'scopeAndContent' => ['unidad' => __('Contenido'), 'agrupacion' => __('Alcance y contenido'), 'general' => __('Alcance y contenido')],
        'extentAndMedium' => ['unidad' => __('Folios'), 'agrupacion' => __('Volumen y soporte'), 'general' => __('Volumen y soporte')],
        'dates' => ['unidad' => __('Fecha'), 'agrupacion' => __('Fechas'), 'general' => __('Fechas')],
    ];

    // Ruta jerárquica (solo lectura).
    $ancestors = arUnicaucaCatalogingContext::ancestors(
        $isEdit || $isDuplicate ? null : $sf_data->getRaw('parent'),
        $isEdit || $isDuplicate ? $sf_data->getRaw('resource') : null
    );
    // En un duplicado `resource` es el original: sus ancestros son los del duplicado y él mismo no entra en la ruta.
?>

<div class="ah-cataloging-form" data-ah-profile="<?php echo $profile; ?>" data-ah-state="<?php echo $initial ? 'initial' : 'ready'; ?>"
  data-ah-level-profiles="<?php echo htmlspecialchars(json_encode((object) $levelProfiles), ENT_QUOTES); ?>"
  data-ah-profile-titles="<?php echo htmlspecialchars(json_encode($profileTitles, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>">
  <?php echo $form->renderGlobalErrors(); ?>

  <?php if ($isEdit) { ?>
    <?php echo $form->renderFormTag(url_for([$resource, 'module' => 'informationobject', 'action' => 'edit']), ['id' => 'editForm']); ?>
  <?php } else { ?>
    <?php echo $form->renderFormTag(url_for(['module' => 'informationobject', 'action' => 'add']), ['id' => 'editForm']); ?>
  <?php } ?>

    <?php echo $form->renderHiddenFields(); ?>

    <header class="ah-context">
      <h2 id="ah-context-heading" class="ah-section-title">
        <?php echo $isEdit ? __('Descripción archivística') : __('Nueva descripción archivística'); ?>
      </h2>

      <nav class="ah-location" aria-label="<?php echo __('Ubicación en la jerarquía'); ?>">
        <span class="ah-location-label"><?php echo __('Ubicación:'); ?></span>
        <ol class="ah-location-path">
          <?php if ([] === $ancestors) { ?>
            <li><?php echo __('Nivel superior (sin descripción superior)'); ?></li>
          <?php } ?>
          <?php foreach ($ancestors as $item) { ?>
            <li><?php echo esc_specialchars($item['label']); ?><?php if (null !== $item['level']) { ?> <span class="ah-location-level">(<?php echo esc_specialchars($item['level']); ?>)</span><?php } ?></li>
          <?php } ?>
          <?php if ($isEdit) { ?>
            <?php $current = arUnicaucaCatalogingContext::current($sf_data->getRaw('resource')); ?>
            <li aria-current="location"><?php echo esc_specialchars($current['label']); ?><?php if (null !== $current['level']) { ?> <span class="ah-location-level">(<?php echo esc_specialchars($current['level']); ?>)</span><?php } ?></li>
          <?php } else { ?>
            <li aria-current="location" class="ah-location-new"><?php echo __('[Nueva descripción]'); ?></li>
          <?php } ?>
        </ol>
      </nav>

      <div class="row ah-row ah-level-row">
        <div class="col-md-5">
          <?php echo $dailyField(
              $form->levelOfDescription,
              __('Nivel de descripción'),
              __('Unidad documental simple: una unidad formada por un solo documento. Unidad documental compuesta: una unidad formada por varios documentos relacionados. Fondo, sección, serie y subserie organizan jerárquicamente las descripciones.')
          ); ?>
        </div>
        <div class="col-md-7 ah-no-level">
          <button type="button" class="btn btn-link ah-no-level-button" data-ah-no-level>
            <?php echo __('Describir sin nivel'); ?>
          </button>
        </div>
      </div>

      <p id="ah-profile-status" class="visually-hidden" role="status" aria-live="polite"></p>
    </header>

    <section class="ah-daily" aria-labelledby="ah-daily-heading">
      <h2 id="ah-daily-heading" class="ah-section-title"><?php echo __('Ficha de catalogación'); ?>
        <span class="ah-profile-name" data-ah-profile-name><?php echo $initial ? '' : '— '.$profileTitles[$profile]; ?></span>
      </h2>

      <div class="row ah-row">
        <div class="col-md-7 ah-switch"<?php echo $switchAttrs('identifier', arUnicaucaCatalogingLevels::PROFILE_UNIT, $form->identifier); ?><?php echo $labelAttr($labels['identifier']); ?>>
          <?php echo $dailyField(
              $form->identifier,
              $labelFor($labels['identifier']),
              __('Escribe el identificador tal como figura en la descripción (por ejemplo, la signatura completa de una unidad documental: 5640 (Ind. C II -24 mn)). Déjalo vacío si no tiene.')
          ); ?>
        </div>
      </div>

      <div class="ah-switch"<?php echo $switchAttrs('title', arUnicaucaCatalogingLevels::PROFILE_GROUPING, $form->title); ?>>
        <?php echo render_field($form->title
            ->help(__('Opcional. Si se deja vacío, la descripción se guarda sin título.'))
            ->label(__('Title')), $resource); ?>
      </div>

      <div class="ah-switch"<?php echo $switchAttrs('creators', arUnicaucaCatalogingLevels::PROFILE_GROUPING, $form->creators); ?>>
        <?php
            $creatorInputs = '<input class="list" type="hidden" value="'
                .url_for(['module' => 'actor', 'action' => 'autocomplete', 'showOnlyActors' => 'true'])
                .'">';
            if (QubitAcl::check(QubitActor::getRoot(), 'create')) {
                $creatorInputs .= '<input class="add" type="hidden" data-link-existing="true" value="'
                    .url_for(['module' => 'actor', 'action' => 'add'])
                    .' #authorizedFormOfName">';
            }
            echo render_field(
                $form->creators
                    ->label(__('Productor'))
                    ->help(__(
                        'Record the name of the organization(s) or the individual(s) responsible for the creation, accumulation and maintenance of the records in the unit of description. Search for an existing name in the authority records by typing the first few characters of the name. Alternatively, type a new name to create and link to a new authority record. (ISAD 3.2.1)'
                    )),
                null,
                ['class' => 'form-autocomplete', 'extraInputs' => $creatorInputs]
            );
        ?>
      </div>

      <div class="row ah-row">
        <div class="col-md-6 ah-switch"<?php echo $switchAttrs('remitentes', arUnicaucaCatalogingLevels::PROFILE_UNIT, $form->remitentes); ?>>
          <?php echo $dailyField(
              $form->remitentes,
              __('Remitente'),
              $actorHelp,
              null,
              ['class' => 'form-autocomplete', 'extraInputs' => $actorInputs]
          ); ?>
        </div>
        <div class="col-md-6 ah-switch"<?php echo $switchAttrs('destinatarios', arUnicaucaCatalogingLevels::PROFILE_UNIT, $form->destinatarios); ?>>
          <?php echo $dailyField(
              $form->destinatarios,
              __('Destinatario'),
              $actorHelp,
              null,
              ['class' => 'form-autocomplete', 'extraInputs' => $actorInputs]
          ); ?>
        </div>
      </div>

      <div<?php echo $labelAttr($labels['scopeAndContent']); ?>>
        <?php echo $dailyField($form->scopeAndContent, $labelFor($labels['scopeAndContent']), null, $resource, ['rows' => 5]); ?>
      </div>

      <div class="row ah-row">
        <div class="col-md-8 ah-switch"<?php echo $switchAttrs('placeAccessPoints', arUnicaucaCatalogingLevels::PROFILE_UNIT, $form->placeAccessPoints); ?>>
          <?php
              $taxonomy = QubitTaxonomy::getById(QubitTaxonomy::PLACE_ID);
              $taxonomyUrl = url_for([$taxonomy, 'module' => 'taxonomy']);
              $placeInputs = '<input class="list" type="hidden" value="'
                  .url_for(['module' => 'term', 'action' => 'autocomplete', 'taxonomy' => $taxonomyUrl])
                  .'">';
              if (QubitAcl::check($taxonomy, 'createTerm')) {
                  $placeInputs .= '<input class="add" type="hidden" data-link-existing="true" value="'
                      .url_for(['module' => 'term', 'action' => 'add', 'taxonomy' => $taxonomyUrl])
                      .' #name">';
              }
              echo $dailyField(
                  $form->placeAccessPoints,
                  __('Lugar de procedencia'),
                  __('Selecciona un lugar de la lista o escribe uno nuevo. Puedes agregar varios.'),
                  null,
                  ['class' => 'form-autocomplete', 'extraInputs' => $placeInputs]
              );
          ?>
        </div>
      </div>

      <?php echo get_partial('catalogingDates', $sf_data->getRaw('eventComponent')->getVarHolder()->getAll() + [
          'label' => $labelFor($labels['dates']),
          'labelsByProfile' => $labels['dates'],
      ]); ?>

      <div class="row ah-row">
        <div class="col-md-8"<?php echo $labelAttr($labels['extentAndMedium']); ?>>
          <?php echo $dailyField($form->extentAndMedium, $labelFor($labels['extentAndMedium']), null, $resource, ['rows' => 2]); ?>
        </div>
      </div>

      <?php echo get_partial('catalogingNotes', $sf_data->getRaw('notesComponent')->getVarHolder()->getAll() + [
          'label' => __('Observaciones'),
          'addLabel' => __('Agregar otra observación'),
          'removeLabel' => __('Quitar esta observación'),
      ]); ?>

      <?php // Campos secundarios del perfil actual: solo se ofrece revelar los que están ocultos (js/cataloging-form.js). ?>
      <div class="ah-reveal" data-ah-reveal-area hidden>
        <p class="ah-reveal-title" id="ah-reveal-title"><?php echo __('Otros campos de esta ficha'); ?></p>
        <ul class="ah-reveal-list" aria-labelledby="ah-reveal-title">
          <?php foreach ([
              'title' => __('Mostrar Título'),
              'creators' => __('Mostrar Productor'),
              'remitentes' => __('Mostrar Remitente'),
              'destinatarios' => __('Mostrar Destinatario'),
              'identifier' => __('Mostrar Identificador'),
              'placeAccessPoints' => __('Mostrar Lugar'),
          ] as $key => $text) { ?>
            <li data-ah-reveal-item="<?php echo $key; ?>" hidden>
              <button type="button" class="btn btn-link" data-ah-reveal="<?php echo $key; ?>"><?php echo $text; ?></button>
            </li>
          <?php } ?>
        </ul>
      </div>
    </section>

    <section class="ah-additional" aria-labelledby="ah-additional-toggle">
      <button
        id="ah-additional-toggle"
        class="ah-additional-toggle<?php echo $additionalOpen ? '' : ' collapsed'; ?>"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#ah-additional-fields"
        aria-expanded="<?php echo $additionalOpen ? 'true' : 'false'; ?>"
        aria-controls="ah-additional-fields">
        <i class="fas fa-chevron-down ah-additional-icon" aria-hidden="true"></i>
        <span class="ah-when-collapsed"><?php echo __('Mostrar campos adicionales'); ?></span>
        <span class="ah-when-expanded"><?php echo __('Ocultar campos adicionales'); ?></span>
      </button>
      <p class="form-text ah-additional-summary">
        <?php echo __('Condiciones de acceso, puntos de acceso, control de la descripción y demás campos ISAD(G).'); ?>
        <?php if (0 < $additionalFilled) { ?>
          <strong><?php echo 1 == $additionalFilled
              ? __('1 campo adicional tiene datos.')
              : __('%1% campos adicionales tienen datos.', ['%1%' => $additionalFilled]); ?></strong>
        <?php } ?>
        <?php echo __('Se conservan al guardar aunque esta sección esté cerrada.'); ?>
      </p>

      <div id="ah-additional-fields" class="collapse<?php echo $additionalOpen ? ' show' : ''; ?>">
        <div class="accordion">
          <div class="accordion-item">
            <h2 class="accordion-header" id="identity-heading">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#identity-collapse" aria-expanded="false" aria-controls="identity-collapse">
                <?php echo __('Identity area'); ?>
              </button>
            </h2>
            <div id="identity-collapse" class="accordion-collapse collapse" aria-labelledby="identity-heading">
              <div class="accordion-body">
                <?php if ($isad->referenceCode) { ?>
                  <div class="mb-3">
                    <h3 class="fs-6 mb-2">
                      <?php echo __('Reference code'); ?>
                    </h3>
                    <span class="text-muted">
                      <?php echo render_value_inline($isad->referenceCode); ?>
                    </span>
                  </div>
                <?php } ?>

                <?php echo get_partial(
                    'catalogingIdentifierOptions',
                    ['mask' => $mask] + $sf_data->getRaw('alternativeIdentifiersComponent')->getVarHolder()->getAll()
                ); ?>

                <?php echo get_partial('catalogingChildLevels', ['help' => __('Identifier: Provide a specific local reference code, control number, or other unique identifier. Level of description: Record the level of this unit of description. Title: Provide either a formal title or a concise supplied title in accordance with the rules of multilevel description and national conventions.')]); ?>
              </div>
            </div>
          </div>
          <div class="accordion-item">
            <h2 class="accordion-header" id="context-heading">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#context-collapse" aria-expanded="false" aria-controls="context-collapse">
                <?php echo __('Context area'); ?>
              </button>
            </h2>
            <div id="context-collapse" class="accordion-collapse collapse" aria-labelledby="context-heading">
              <div class="accordion-body">
                <?php echo render_field(
                    $form->repository->help(__(
                        'Record the name of the organization which has custody of the archival material. Search for an existing name in the archival institution records by typing the first few characters of the name. Alternatively, type a new name to create and link to a new archival institution record.'
                    )),
                    null,
                    [
                        'class' => 'form-autocomplete',
                        'extraInputs' => '<input class="list" type="hidden" value="'
                            .url_for($sf_data->getRaw('repoAcParams'))
                            .'"><input class="add" type="hidden" data-link-existing="true" value="'
                            .url_for(['module' => 'repository', 'action' => 'add'])
                            .' #authorizedFormOfName">',
                    ]
                ); ?>

                <?php echo render_field($form->archivalHistory
                    ->help(__('Record the successive transfers of ownership, responsibility and/or custody of the unit of description and indicate those actions, such as history of the arrangement, production of contemporary finding aids, re-use of the records for other purposes or software migrations, that have contributed to its present structure and arrangement. Give the dates of these actions, insofar as they can be ascertained. If the archival history is unknown, record that information. (ISAD 3.2.3)')), $resource); ?>

                <?php echo render_field($form->acquisition
                    ->help(__('Record the source from which the unit of description was acquired and the date and/or method of acquisition if any or all of this information is not confidential. If the source is unknown, record that information. Optionally, add accession numbers or codes. (ISAD 3.2.4)'))
                    ->label(__('Immediate source of acquisition or transfer')), $resource); ?>
              </div>
            </div>
          </div>
          <div class="accordion-item">
            <h2 class="accordion-header" id="content-heading">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#content-collapse" aria-expanded="false" aria-controls="content-collapse">
                <?php echo __('Content and structure area'); ?>
              </button>
            </h2>
            <div id="content-collapse" class="accordion-collapse collapse" aria-labelledby="content-heading">
              <div class="accordion-body">
                <?php echo render_field($form->appraisal
                    ->help(__('Record appraisal, destruction and scheduling actions taken on or planned for the unit of description, especially if they may affect the interpretation of the material. (ISAD 3.3.2)'))
                    ->label(__('Appraisal, destruction and scheduling')), $resource); ?>

                <?php echo render_field($form->accruals
                    ->help(__('Indicate if accruals are expected. Where appropriate, give an estimate of their quantity and frequency. (ISAD 3.3.3)')), $resource); ?>

                <?php echo render_field($form->arrangement
                    ->help(__('Specify the internal structure, order and/or the system of classification of the unit of description. Note how these have been treated by the archivist. For electronic records, record or reference information on system design. (ISAD 3.3.4)'))
                    ->label(__('System of arrangement')), $resource); ?>
              </div>
            </div>
          </div>
          <div class="accordion-item">
            <h2 class="accordion-header" id="conditions-heading">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#conditions-collapse" aria-expanded="false" aria-controls="conditions-collapse">
                <?php echo __('Conditions of access and use area'); ?>
              </button>
            </h2>
            <div id="conditions-collapse" class="accordion-collapse collapse" aria-labelledby="conditions-heading">
              <div class="accordion-body">
                <?php echo render_field($form->accessConditions
                    ->help(__('Specify the law or legal status, contract, regulation or policy that affects access to the unit of description. Indicate the extent of the period of closure and the date at which the material will open when appropriate. (ISAD 3.4.1)'))
                    ->label(__('Conditions governing access')), $resource); ?>

                <?php echo render_field($form->reproductionConditions
                    ->help(__('Give information about conditions, such as copyright, governing the reproduction of the unit of description after access has been provided. If the existence of such conditions is unknown, record this. If there are no conditions, no statement is necessary. (ISAD 3.4.2)'))
                    ->label(__('Conditions governing reproduction')), $resource); ?>

                <?php echo render_field(
                    $form->language
                        ->help(__('Record the language(s) of the materials comprising the unit of description. (ISAD 3.4.3)'))
                        ->label(__('Languages of the material')),
                    null,
                    ['class' => 'form-autocomplete']
                ); ?>

                <?php echo render_field(
                    $form->script
                        ->help(__('Record the script(s) of the materials comprising the unit of description. (ISAD 3.4.3)'))
                        ->label(__('Scripts of the material')),
                    null,
                    ['class' => 'form-autocomplete']
                ); ?>

                <?php echo render_field($form->languageNotes
                    ->help(__('Note any distinctive alphabets, scripts, symbol systems or abbreviations employed. (ISAD 3.4.3)'))
                    ->label(__('Language and script notes')), $isad); ?>

                <?php echo render_field($form->physicalCharacteristics
                    ->help(__('Indicate any important physical conditions, such as preservation requirements, that affect the use of the unit of description. Note any software and/or hardware required to access the unit of description.'))
                    ->label(__('Physical characteristics and technical requirements')), $resource); ?>

                <?php echo render_field($form->findingAids
                    ->help(__('Give information about any finding aids that the repository or records creator may have that provide information relating to the context and contents of the unit of description. If appropriate, include information on where to obtain a copy. (ISAD 3.4.5)')), $resource); ?>
              </div>
            </div>
          </div>
          <div class="accordion-item">
            <h2 class="accordion-header" id="allied-heading">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#allied-collapse" aria-expanded="false" aria-controls="allied-collapse">
                <?php echo __('Allied materials area'); ?>
              </button>
            </h2>
            <div id="allied-collapse" class="accordion-collapse collapse" aria-labelledby="allied-heading">
              <div class="accordion-body">
                <?php echo render_field($form->locationOfOriginals
                    ->help(__('If the original of the unit of description is available (either in the institution or elsewhere) record its location, together with any significant control numbers. If the originals no longer exist, or their location is unknown, give that information. (ISAD 3.5.1)'))
                    ->label(__('Existence and location of originals')), $resource); ?>

                <?php echo render_field($form->locationOfCopies
                    ->help(__('If the copy of the unit of description is available (either in the institution or elsewhere) record its location, together with any significant control numbers. (ISAD 3.5.2)'))
                    ->label(__('Existence and location of copies')), $resource); ?>

                <?php echo render_field($form->relatedUnitsOfDescription
                    ->help(__('Record information about units of description in the same repository or elsewhere that are related by provenance or other association(s). Use appropriate introductory wording and explain the nature of the relationship . If the related unit of description is a finding aid, use the finding aids element of description (3.4.5) to make the reference to it. (ISAD 3.5.3)'))
                    ->label(__('Related units of description')), $resource); ?>

                <?php
                    $extraInputs = '<input class="list" type="hidden" value="'
                        .url_for(['module' => 'informationobject', 'action' => 'autocomplete'])
                        .'">';
                    if (QubitAcl::check(QubitInformationObject::getRoot(), 'create')) {
                        $extraInputs .= '<input class="add" type="hidden" data-link-existing="true" value="'
                            .url_for(['module' => 'informationobject', 'action' => 'add'])
                            .' #title">';
                    }
                    echo render_field(
                        $form->relatedMaterialDescriptions->label(__('Related descriptions'))->help(__(
                            'To create a relationship between this description and another description held in AtoM, begin typing the name of the related description and select it from the autocomplete drop-down menu when it appears below. Multiple relationships can be created.'
                        )),
                        null,
                        ['class' => 'form-autocomplete', 'extraInputs' => $extraInputs]
                    );
                ?>

                <?php echo get_partial('catalogingObjectNotes', $sf_data->getRaw('publicationNotesComponent')->getVarHolder()->getAll()); ?>
              </div>
            </div>
          </div>
          <div class="accordion-item">
            <h2 class="accordion-header" id="access-heading">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#access-collapse" aria-expanded="false" aria-controls="access-collapse">
                <?php echo __('Access points'); ?>
              </button>
            </h2>
            <div id="access-collapse" class="accordion-collapse collapse" aria-labelledby="access-heading">
              <div class="accordion-body">
                <?php
                    $taxonomy = QubitTaxonomy::getById(QubitTaxonomy::SUBJECT_ID);
                    $taxonomyUrl = url_for([$taxonomy, 'module' => 'taxonomy']);
                    $extraInputs = '<input class="list" type="hidden" value="'
                        .url_for(['module' => 'term', 'action' => 'autocomplete', 'taxonomy' => $taxonomyUrl])
                        .'">';
                    if (QubitAcl::check($taxonomy, 'createTerm')) {
                        $extraInputs .= '<input class="add" type="hidden" data-link-existing="true" value="'
                            .url_for(['module' => 'term', 'action' => 'add', 'taxonomy' => $taxonomyUrl])
                            .' #name">';
                    }
                    echo render_field(
                        $form->subjectAccessPoints->label(__('Subject access points')),
                        null,
                        ['class' => 'form-autocomplete', 'extraInputs' => $extraInputs]
                    );
                ?>

                <?php
                    $taxonomy = QubitTaxonomy::getById(QubitTaxonomy::GENRE_ID);
                    $taxonomyUrl = url_for([$taxonomy, 'module' => 'taxonomy']);
                    $extraInputs = '<input class="list" type="hidden" value="'
                        .url_for(['module' => 'term', 'action' => 'autocomplete', 'taxonomy' => $taxonomyUrl])
                        .'">';
                    if (QubitAcl::check($taxonomy, 'createTerm')) {
                        $extraInputs .= '<input class="add" type="hidden" data-link-existing="true" value="'
                            .url_for(['module' => 'term', 'action' => 'add', 'taxonomy' => $taxonomyUrl])
                            .' #name">';
                    }
                    echo render_field(
                        $form->genreAccessPoints->label(__('Genre access points')),
                        null,
                        ['class' => 'form-autocomplete', 'extraInputs' => $extraInputs]
                    );
                ?>

                <?php
                    $extraInputs = '<input class="list" type="hidden" value="'
                        .url_for(['module' => 'actor', 'action' => 'autocomplete', 'showOnlyActors' => 'true'])
                        .'">';
                    if (QubitAcl::check(QubitActor::getRoot(), 'create')) {
                        $extraInputs .= '<input class="add" type="hidden" data-link-existing="true" value="'
                            .url_for(['module' => 'actor', 'action' => 'add'])
                            .' #authorizedFormOfName">';
                    }
                    echo render_field(
                        $form->nameAccessPoints->label(__('Name access points (subjects)')),
                        null,
                        ['class' => 'form-autocomplete', 'extraInputs' => $extraInputs]
                    );
                ?>
              </div>
            </div>
          </div>
          <div class="accordion-item">
            <h2 class="accordion-header" id="description-heading">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#description-collapse" aria-expanded="false" aria-controls="description-collapse">
                <?php echo __('Description control area'); ?>
              </button>
            </h2>
            <div id="description-collapse" class="accordion-collapse collapse" aria-labelledby="description-heading">
              <div class="accordion-body">
                <?php echo render_field($form->descriptionIdentifier
                    ->help(__('Record a unique description identifier in accordance with local and/or national conventions. If the description is to be used internationally, record the code of the country in which the description was created in accordance with the latest version of ISO 3166 - Codes for the representation of names of countries. Where the creator of the description is an international organisation, give the organisational identifier in place of the country code.'))
                    ->label(__('Description identifier'))
                ); ?>

                <?php echo render_field($form->institutionResponsibleIdentifier
                    ->help(__('Record the full authorised form of name(s) of the agency(ies) responsible for creating, modifying or disseminating the description or, alternatively, record a code for the agency in accordance with the national or international agency code standard.'))
                    ->label(__('Institution identifier')), $resource); ?>

                <?php echo render_field($form->rules
                    ->help(__('Record the international, national and/or local rules or conventions followed in preparing the description. (ISAD 3.7.2)'))
                    ->label(__('Rules or conventions')), $resource); ?>

                <?php echo render_field($form->descriptionStatus
                    ->label(__('Status'))
                    ->help(__('Record the current status of the description, indicating whether it is a draft, finalized and/or revised or deleted.'))
                ); ?>

                <?php echo render_field($form->descriptionDetail
                    ->help(__('Record whether the description consists of a minimal, partial or full level of detail in accordance with relevant international and/or national guidelines and/or rules.'))
                    ->label(__('Level of detail'))
                ); ?>

                <?php echo render_field($form->revisionHistory
                    ->help(__('Record the date(s) the entry was prepared and/or revised.'))
                    ->label(__('Dates of creation, revision and deletion')), $resource); ?>

                <?php echo render_field(
                    $form->languageOfDescription
                        ->help(__('Indicate the language(s) used to create the description of the archival material.'))
                        ->label(__('Language(s)')),
                    null,
                    ['class' => 'form-autocomplete']
                ); ?>

                <?php echo render_field(
                    $form->scriptOfDescription
                        ->help(__('Indicate the script(s) used to create the description of the archival material.'))
                        ->label(__('Script(s)')),
                    null,
                    ['class' => 'form-autocomplete']
                ); ?>

                <?php echo render_field($form->sources
                    ->help(__('Record citations for any external sources used in the archival description (such as the Scope and Content, Archival History, or Notes fields).'))
                    ->label(__('Sources')), $resource); ?>

                <?php echo get_partial('catalogingObjectNotes', $sf_data->getRaw('archivistsNotesComponent')->getVarHolder()->getAll()); ?>
              </div>
            </div>
          </div>
          <?php echo get_partial('informationobject/adminInfo', ['form' => $form, 'resource' => $resource]); ?>
        </div>
      </div>
    </section>

    <div class="ah-actions">
      <?php echo get_partial('informationobject/editActions', ['resource' => (null !== $parent ? $parent : $resource)]); ?>
    </div>

  </form>

</div>

<?php end_slot(); ?>
