<?php

/*
 * Prueba de integración del formulario de catalogación (sfIsadPlugin/edit con el override de arUnicaucaB5Plugin).
 *
 * La lanza tests/test-cataloging-form.sh dentro del servicio `atom` con `php symfony tools:run`. Conduce el formulario REAL
 * por HTTP (login de admin, GET del formulario, POST) y serializa el <form id="editForm"> como un navegador sin JavaScript:
 * los selects múltiples envían sus opciones seleccionadas con el mismo nombre (`remitentes[]`, ...) que las entradas ocultas
 * que crea el autocomplete. Toda la observación es SQL directo (QubitPdo), independiente del código bajo prueba.
 *
 * Los actores y lugares de prueba se crean con el modelo (lo que el autocomplete encontraría ya existente). El alta de un
 * nombre nuevo desde el autocomplete (iframe de actor/add) es JavaScript y no se cubre aquí.
 *
 * Todo lo que crea (descripciones, actores, términos) se borra al terminar, también si falla. Exit: 0 todo PASS; 1 algún FAIL.
 *
 * Entorno: CATALOGING_TEST_URL (por defecto http://nginx), CATALOGING_TEST_EMAIL, CATALOGING_TEST_PASSWORD.
 */
(static function (): void {
    $base = rtrim(getenv('CATALOGING_TEST_URL') ?: 'http://nginx', '/');
    $email = getenv('CATALOGING_TEST_EMAIL') ?: 'admin@example.com';
    $password = getenv('CATALOGING_TEST_PASSWORD') ?: 'admin_dev_12345';
    $run = bin2hex(random_bytes(4));
    $fails = 0;
    $created = ['io' => [], 'actor' => [], 'term' => []];

    sfContext::getInstance()->getUser()->setCulture('es');

    $check = static function (string $label, $expected, $actual) use (&$fails): void {
        if ($expected === $actual) {
            echo 'PASS  '.$label."\n";
        } else {
            ++$fails;
            echo 'FAIL  '.$label."\n      esperado: ".json_encode($expected, JSON_UNESCAPED_UNICODE)
                ."\n      obtenido: ".json_encode($actual, JSON_UNESCAPED_UNICODE)."\n";
        }
    };
    $q = static fn (string $sql, array $params = []) => QubitPdo::fetchAll($sql, $params, ['fetchMode' => PDO::FETCH_ASSOC]);
    $col = static fn (string $sql, array $params = []) => QubitPdo::fetchAll($sql, $params, ['fetchMode' => PDO::FETCH_COLUMN]);
    $one = static fn (string $sql, array $params = []) => QubitPdo::fetchColumn($sql, $params);

    // --- HTTP ---------------------------------------------------------------------------------------------------------
    // Un único handle: su motor de cookies mantiene la sesión entre peticiones.
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_TIMEOUT => 120,
    ]);
    $request = static function (string $path, ?string $body = null) use ($base, $curl): array {
        curl_setopt($curl, CURLOPT_URL, $base.$path);
        if (null === $body) {
            curl_setopt($curl, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($curl);
        if (false === $raw) {
            throw new RuntimeException('HTTP '.$path.': '.curl_error($curl));
        }
        $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $size);
        $location = preg_match('/^Location:\s*(\S+)/mi', $headers, $m) ? $m[1] : null;

        return ['code' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => substr($raw, $size), 'location' => $location];
    };
    $encode = static fn (array $pairs) => implode('&', array_map(static fn ($p) => rawurlencode($p[0]).'='.rawurlencode($p[1]), $pairs));

    // Pares [nombre, valor] que un navegador enviaría desde el formulario (sin JavaScript), en orden de documento.
    $serialize = static function (string $html, string $formId = 'editForm'): array {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $form = (new DOMXPath($dom))->query("//form[@id='{$formId}']")->item(0);
        if (null === $form) {
            throw new RuntimeException("no hay form#{$formId}");
        }
        $pairs = [];
        foreach ((new DOMXPath($dom))->query('.//input|.//textarea|.//select', $form) as $el) {
            $name = $el->getAttribute('name');
            if ('' === $name || $el->hasAttribute('disabled')) {
                continue;
            }
            switch ($el->nodeName) {
                case 'input':
                    $type = strtolower($el->getAttribute('type') ?: 'text');
                    if (in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) {
                        break;
                    }
                    if (in_array($type, ['checkbox', 'radio'], true)) {
                        if ($el->hasAttribute('checked')) {
                            $pairs[] = [$name, $el->hasAttribute('value') ? $el->getAttribute('value') : 'on'];
                        }

                        break;
                    }
                    $pairs[] = [$name, $el->getAttribute('value')];

                    break;

                case 'textarea':
                    $pairs[] = [$name, preg_replace('/^\r?\n/', '', $el->textContent)];

                    break;

                case 'select':
                    $options = (new DOMXPath($dom))->query('.//option', $el);
                    $selected = [];
                    foreach ($options as $o) {
                        if ($o->hasAttribute('selected')) {
                            $selected[] = $o->getAttribute('value');
                        }
                    }
                    if ($el->hasAttribute('multiple')) {
                        foreach ($selected as $v) {
                            $pairs[] = [$name, $v];
                        }
                    } elseif ([] !== $selected) {
                        $pairs[] = [$name, end($selected)];
                    } elseif (0 < $options->length) {
                        $pairs[] = [$name, $options->item(0)->getAttribute('value')];
                    }

                    break;
            }
        }

        return $pairs;
    };
    $set = static function (array $pairs, string $name, array $values): array {
        $pairs = array_values(array_filter($pairs, static fn ($p) => $p[0] !== $name));
        foreach ($values as $v) {
            $pairs[] = [$name, $v];
        }

        return $pairs;
    };
    $get = static function (array $pairs, string $name): array {
        return array_values(array_map(static fn ($p) => $p[1], array_filter($pairs, static fn ($p) => $p[0] === $name)));
    };
    // Guarda el formulario y devuelve el id de la descripción (la action redirige a su slug cuando el POST es válido).
    $save = static function (string $path, array $pairs) use ($request, $encode, $one): array {
        $r = $request($path, $encode($pairs));
        $id = null;
        if (302 === $r['code'] && null !== $r['location']) {
            $slug = basename(parse_url($r['location'], PHP_URL_PATH));
            $id = (int) $one('SELECT object_id FROM slug WHERE slug = ?', [$slug]) ?: null;
        }

        return ['id' => $id] + $r;
    };
    $url = static fn (QubitObject $o) => '/index.php/'.$o->slug;
    $editPath = static fn (int $id) => '/index.php/'.$one('SELECT slug FROM slug WHERE object_id = ?', [$id]).'/edit';

    // --- observación ----------------------------------------------------------------------------------------------------
    $roleTypeId = static fn (string $code) => (int) $one(
        'SELECT id FROM term WHERE taxonomy_id = ? AND code = ? COLLATE utf8mb4_bin',
        [QubitTaxonomy::EVENT_TYPE_ID, $code]
    );
    $remitenteId = $roleTypeId('archivo-historico:remitente');
    $destinatarioId = $roleTypeId('archivo-historico:destinatario');
    $seccionId = (int) $one('SELECT id FROM term WHERE taxonomy_id = ? AND code = ? COLLATE utf8mb4_bin', [QubitTaxonomy::LEVEL_OF_DESCRIPTION_ID, 'archivo-historico:seccion']);
    // Actores enlazados por eventos de ese tipo, en orden de alta.
    $roleActors = static fn (int $io, int $type) => array_map('intval', $col('SELECT actor_id FROM event WHERE object_id = ? AND type_id = ? ORDER BY id', [$io, $type]));
    $roleDated = static fn (int $io) => (int) $one(
        'SELECT COUNT(*) FROM event e LEFT JOIN event_i18n i ON i.id = e.id WHERE e.object_id = ? AND e.type_id IN (?, ?)
           AND (e.start_date IS NOT NULL OR e.end_date IS NOT NULL OR i.date IS NOT NULL)',
        [$io, $remitenteId, $destinatarioId]
    );
    $i18n = static fn (int $io, string $column) => $one("SELECT {$column} FROM information_object_i18n WHERE id = ? AND culture = 'es'", [$io]);
    // Huella de todo lo que pertenece a la descripción, menos las columnas indicadas de information_object_i18n.
    $snapshot = static function (int $io, array $skip = []) use ($q): array {
        $i18n = $q('SELECT * FROM information_object_i18n WHERE id = ? ORDER BY culture', [$io]);
        foreach ($i18n as &$row) {
            foreach ($skip as $c) {
                unset($row[$c]);
            }
        }
        unset($row);

        return [
            'information_object' => $q('SELECT * FROM information_object WHERE id = ?', [$io]),
            'information_object_i18n' => $i18n,
            'event' => $q('SELECT e.*, i.culture, i.name, i.description, i.date FROM event e LEFT JOIN event_i18n i ON i.id = e.id WHERE e.object_id = ? ORDER BY e.id, i.culture', [$io]),
            'note' => $q('SELECT n.id, n.type_id, n.scope, n.source_culture, i.culture, i.content FROM note n LEFT JOIN note_i18n i ON i.id = n.id WHERE n.object_id = ? ORDER BY n.id, i.culture', [$io]),
            'property' => $q('SELECT p.id, p.name, p.scope, p.source_culture, i.culture, i.value FROM property p LEFT JOIN property_i18n i ON i.id = p.id WHERE p.object_id = ? ORDER BY p.id, i.culture', [$io]),
            'relation' => $q('SELECT * FROM relation WHERE subject_id = ? OR object_id = ? ORDER BY id', [$io, $io]),
            'object_term_relation' => $q('SELECT * FROM object_term_relation WHERE object_id = ? ORDER BY id', [$io]),
            'status' => $q('SELECT * FROM status WHERE object_id = ? ORDER BY id', [$io]),
        ];
    };
    $diff = static function (array $a, array $b): array {
        $out = [];
        foreach ($a as $table => $rows) {
            if ($rows != $b[$table]) {
                $out[] = $table;
            }
        }

        return $out;
    };
    // Términos de niveles y tipos de evento (sin lft/rgt: altas en otras taxonomías renumeran el nested set).
    $termsFp = static fn () => hash('sha256', json_encode($q(
        'SELECT t.id, t.taxonomy_id, t.code, t.parent_id, t.source_culture, i.culture, i.name FROM term t LEFT JOIN term_i18n i ON i.id = t.id
          WHERE t.taxonomy_id IN (?, ?) ORDER BY t.id, i.culture',
        [QubitTaxonomy::LEVEL_OF_DESCRIPTION_ID, QubitTaxonomy::EVENT_TYPE_ID]
    )));
    $actorName = static fn (int $id) => $one("SELECT authorized_form_of_name FROM actor_i18n WHERE id = ? AND culture = 'es'", [$id]);

    // --- fixtures (modelo) ----------------------------------------------------------------------------------------------
    $newActor = static function (string $name) use (&$created): QubitActor {
        $a = new QubitActor();
        $a->parentId = QubitActor::ROOT_ID;
        $a->sourceCulture = 'es';
        $a->setAuthorizedFormOfName($name, ['culture' => 'es']);
        $a->save();
        $created['actor'][] = $a->id;

        return $a;
    };
    $newTerm = static function (int $taxonomy, string $name) use (&$created): QubitTerm {
        $t = new QubitTerm();
        $t->parentId = QubitTerm::ROOT_ID;
        $t->taxonomyId = $taxonomy;
        $t->sourceCulture = 'es';
        $t->setName($name, ['culture' => 'es']);
        $t->save();
        $created['term'][] = $t->id;

        return $t;
    };

    try {
        $termsBefore = $termsFp();

        echo "== Tipos de evento por code ==\n";
        $check('Remitente se resuelve por taxonomía + code', $remitenteId, (int) arUnicaucaCatalogingRoleEvents::getType('archivo-historico:remitente')->id);
        $check('Destinatario se resuelve por taxonomía + code', $destinatarioId, (int) arUnicaucaCatalogingRoleEvents::getType('archivo-historico:destinatario')->id);
        try {
            arUnicaucaCatalogingRoleEvents::getType("archivo-historico:inexistente-{$run}");
            $check('un code inexistente falla (no se adivina otro tipo)', 'excepción', 'sin excepción');
        } catch (sfException $e) {
            $check('un code inexistente falla (no se adivina otro tipo)', 'excepción', 'excepción');
        }

        $a = [];
        foreach (range(1, 8) as $n) {
            $a[$n] = $newActor("Actor prueba {$n} {$run}");
        }
        $p1 = $newTerm(QubitTaxonomy::PLACE_ID, "Popayán prueba {$run}");
        $p2 = $newTerm(QubitTaxonomy::PLACE_ID, "Quito prueba {$run}");
        $s1 = $newTerm(QubitTaxonomy::SUBJECT_ID, "Materia prueba {$run}");
        $actorNamesBefore = array_map(static fn ($x) => $actorName($x->id), $a);

        echo "== Login ==\n";
        $login = $request('/index.php/user/login');
        $token = preg_match('/name="_csrf_token" value="([^"]+)"/', $login['body'], $m) ? $m[1] : '';
        $r = $request('/index.php/user/login', $encode([['_csrf_token', (string) $token], ['email', $email], ['password', $password]]));
        $check('login de admin', 302, $r['code']);

        echo "== Formulario de alta ==\n";
        $add = $request('/index.php/informationobject/add');
        $check('GET alta: 200 con la superficie de catalogación', [200, true], [$add['code'], false !== strpos($add['body'], 'ah-cataloging-form')]);
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$add['body']);
        $xp = new DOMXPath($dom);
        $levelValues = [];
        foreach ($xp->query("//select[@name='levelOfDescription']//option[@value!='']") as $o) {
            $levelValues[] = basename($o->getAttribute('value'));
        }
        sort($levelValues);
        $levelSlugs = $col('SELECT s.slug FROM term t JOIN slug s ON s.object_id = t.id WHERE t.taxonomy_id = ?', [QubitTaxonomy::LEVEL_OF_DESCRIPTION_ID]);
        sort($levelSlugs);
        $check('Nivel: el selector ofrece todos los términos de la taxonomía, una vez cada uno', $levelSlugs, $levelValues);
        $check('Nivel: grupos "principales" y "otros"', ['Niveles principales', 'Otros niveles'], array_map(
            static fn ($g) => $g->getAttribute('label'),
            iterator_to_array($xp->query("//select[@name='levelOfDescription']/optgroup"))
        ));
        $check('Nivel: Sección está entre los niveles principales', 1, $xp->query("//select[@name='levelOfDescription']/optgroup[1]/option[text()='Sección']")->length);
        $check('Nivel: sin valor por defecto en el alta', 0, $xp->query("//select[@name='levelOfDescription']//option[@selected]")->length);

        echo "== Creación ==\n";
        $form = $serialize($add['body']);
        $check('el formulario incluye los campos adicionales (mismo <form>)', [1, 1, 1], [
            count($get($form, 'title')), count($get($form, 'archivalHistory')), count($get($form, 'accessConditions')),
        ]);
        $signatura = '5640 (Ind. C II -24 mn)';
        $form = $set($form, 'identifier', [$signatura]);
        $form = $set($form, 'levelOfDescription', ['/index.php/'.QubitTerm::getById($seccionId)->slug]);
        $form = $set($form, 'remitentes[]', [$url($a[1]), $url($a[2])]);
        $form = $set($form, 'destinatarios[]', [$url($a[3]), $url($a[4])]);
        $form = $set($form, 'scopeAndContent', ["Carta sobre el envío de víveres.\nSegundo párrafo."]);
        $form = $set($form, 'placeAccessPoints[]', [$url($p1), $url($p2)]);
        $form = $set($form, 'editEvents[0][date]', ['12 de marzo de 1810']);
        $form = $set($form, 'extentAndMedium', ['3 folios']);
        $form = $set($form, 'isadNotes[0][content]', ['Manuscrito. Original; Copia Digitalizada']);
        $r = $save('/index.php/informationobject/add', $form);
        $check('POST alta redirige a la descripción creada', [302, true], [$r['code'], null !== $r['id']]);
        $io = (int) $r['id'];
        $created['io'][] = $io;

        $check('Signatura exacta en identifier', $signatura, $one('SELECT identifier FROM information_object WHERE id = ?', [$io]));
        $check('Nivel = Sección', $seccionId, (int) $one('SELECT level_of_description_id FROM information_object WHERE id = ?', [$io]));
        $check('Título vacío (no se genera ni se copia la Signatura)', null, $i18n($io, 'title'));
        $check('Contenido en scopeAndContent', "Carta sobre el envío de víveres.\nSegundo párrafo.", $i18n($io, 'scope_and_content'));
        $check('Folios en extentAndMedium', '3 folios', $i18n($io, 'extent_and_medium'));
        $places = array_map('intval', $col('SELECT r.term_id FROM object_term_relation r JOIN term t ON t.id = r.term_id WHERE r.object_id = ? AND t.taxonomy_id = ? ORDER BY r.term_id', [$io, QubitTaxonomy::PLACE_ID]));
        $check('Lugares de procedencia', [(int) $p1->id, (int) $p2->id], $places);
        $check('Fecha: un evento Creación sin actor, solo con la expresión', [[
            'type_id' => QubitTerm::CREATION_ID, 'actor_id' => null, 'start_date' => null, 'end_date' => null, 'date' => '12 de marzo de 1810',
        ]], array_map(static function ($e) {
            $e['type_id'] = (int) $e['type_id'];

            return $e;
        }, $q("SELECT e.type_id, e.actor_id, e.start_date, e.end_date, i.date FROM event e JOIN event_i18n i ON i.id = e.id WHERE e.object_id = ? AND e.type_id NOT IN (?, ?)", [$io, $remitenteId, $destinatarioId])));
        $check('Observaciones: una nota general con el texto completo', [['type_id' => QubitTerm::GENERAL_NOTE_ID, 'content' => 'Manuscrito. Original; Copia Digitalizada']], array_map(static function ($n) {
            $n['type_id'] = (int) $n['type_id'];

            return $n;
        }, $q('SELECT n.type_id, i.content FROM note n JOIN note_i18n i ON i.id = n.id WHERE n.object_id = ?', [$io])));
        $check('Remitentes exactos', [(int) $a[1]->id, (int) $a[2]->id], $roleActors($io, $remitenteId));
        $check('Destinatarios exactos', [(int) $a[3]->id, (int) $a[4]->id], $roleActors($io, $destinatarioId));
        $check('eventos de rol sin fecha', 0, $roleDated($io));

        echo "== Edición: Remitente / Destinatario ==\n";
        // Otros eventos que la edición no debe tocar: un evento de Custodia con actor (otro tipo) y el productor (Creación).
        $custody = new QubitEvent();
        $custody->objectId = $io;
        $custody->typeId = QubitTerm::CUSTODY_ID;
        $custody->actorId = $a[8]->id;
        $custody->indexOnSave = false;
        $custody->save();
        $edit = $request($editPath($io));
        $form = $serialize($edit['body']);
        $check('el formulario de edición carga los Remitentes', [$url($a[1]), $url($a[2])], $get($form, 'remitentes[]'));
        $check('el formulario de edición carga los Destinatarios', [$url($a[3]), $url($a[4])], $get($form, 'destinatarios[]'));
        $form = $set($form, 'creators[]', [$url($a[8])]);
        $r = $save($editPath($io), $form);
        $check('guardar sin cambios de rol', 302, $r['code']);
        $before = $snapshot($io, ['scope_and_content']);
        $keptDestinatario = (int) $one('SELECT id FROM event WHERE object_id = ? AND type_id = ? AND actor_id = ?', [$io, $destinatarioId, $a[3]->id]);
        $others = $q('SELECT e.*, i.date FROM event e LEFT JOIN event_i18n i ON i.id = e.id WHERE e.object_id = ? AND e.type_id NOT IN (?, ?) ORDER BY e.id', [$io, $remitenteId, $destinatarioId]);
        $check('hay 3 eventos ajenos a los roles (fecha, productor, custodia)', 3, count($others));

        $form = $serialize($request($editPath($io))['body']);
        // Remitentes [1, 2] → [5, 6]: se reemplaza 1 por 5, se quita 2 y se añade 6. Destinatarios [3, 4] → [3, 7].
        $form = $set($form, 'remitentes[]', [$url($a[5]), $url($a[6])]);
        $form = $set($form, 'destinatarios[]', [$url($a[3]), $url($a[7])]);
        $form = $set($form, 'scopeAndContent', ['Contenido editado.']);
        $r = $save($editPath($io), $form);
        $check('POST edición', 302, $r['code']);
        $check('Remitentes tras reemplazar/quitar/añadir', [(int) $a[5]->id, (int) $a[6]->id], $roleActors($io, $remitenteId));
        $check('Destinatarios tras reemplazar uno', [(int) $a[3]->id, (int) $a[7]->id], $roleActors($io, $destinatarioId));
        $check('el Destinatario conservado mantiene su evento (sin duplicar)', $keptDestinatario, (int) $one('SELECT id FROM event WHERE object_id = ? AND type_id = ? AND actor_id = ?', [$io, $destinatarioId, $a[3]->id]));
        $check('eventos de rol sin fecha tras editar', 0, $roleDated($io));
        $check('Creación, fecha y Custodia intactos', $others, $q('SELECT e.*, i.date FROM event e LEFT JOIN event_i18n i ON i.id = e.id WHERE e.object_id = ? AND e.type_id NOT IN (?, ?) ORDER BY e.id', [$io, $remitenteId, $destinatarioId]));
        $check('Contenido actualizado', 'Contenido editado.', $i18n($io, 'scope_and_content'));
        $after = $snapshot($io, ['scope_and_content']);
        unset($before['event'], $after['event']);
        $check('fuera de roles y Contenido, nada más cambió', [], $diff($before, $after));
        $check('ningún actor fue renombrado', $actorNamesBefore, array_map(static fn ($x) => $actorName($x->id), $a));

        echo "== Título vacío en edición ==\n";
        $check('Título sigue vacío tras editar', null, $i18n($io, 'title'));
        $check('la Signatura sigue exacta', $signatura, $one('SELECT identifier FROM information_object WHERE id = ?', [$io]));

        echo "== Validación de nombres ==\n";
        $count = (int) $one('SELECT COUNT(*) FROM information_object');
        $form = $serialize($request('/index.php/informationobject/add')['body']);
        $form = $set($form, 'identifier', ["error-{$run}"]);
        $form = $set($form, 'remitentes[]', ["/index.php/no-existe-{$run}"]);
        $r = $save('/index.php/informationobject/add', $form);
        $check('un nombre que no es un actor no se guarda: el formulario vuelve con el error', [200, true, true], [
            $r['code'],
            false !== strpos($r['body'], 'Uno de los nombres no se pudo guardar'),
            false !== strpos($r['body'], 'remitentes-errors'),
        ]);
        $check('no se creó ninguna descripción', $count, (int) $one('SELECT COUNT(*) FROM information_object'));

        echo "== Campos adicionales ==\n";
        $form = $serialize($request('/index.php/informationobject/add')['body']);
        $form = $set($form, 'identifier', ["AD-{$run}"]);
        $form = $set($form, 'remitentes[]', [$url($a[1])]);
        $form = $set($form, 'scopeAndContent', ['Contenido inicial.']);
        $form = $set($form, 'title', ["Título existente {$run}"]);
        $form = $set($form, 'archivalHistory', ['Historia archivística de prueba.']);
        $form = $set($form, 'accessConditions', ['Acceso libre.']);
        $form = $set($form, 'physicalCharacteristics', ['Papel con manchas.']);
        $form = $set($form, 'languageNotes', ['Escritura procesal.']);
        $form = $set($form, 'language[]', ['es', 'la']);
        $form = $set($form, 'creators[]', [$url($a[2])]);
        $form = $set($form, 'subjectAccessPoints[]', [$url($s1)]);
        $form = $set($form, 'descriptionIdentifier', ["DI-{$run}"]);
        $form = $set($form, 'isadPublicationNotes[0][content]', ['Nota de publicación.']);
        $form = $set($form, 'isadArchivistsNotes[0][content]', ['Nota del archivero.']);
        $form = $set($form, 'alternativeIdentifiers[0][label]', ['Signatura antigua']);
        $form = $set($form, 'alternativeIdentifiers[0][identifier]', ['C II 24']);
        $form = $set($form, 'editEvents[0][date]', ['ca. 1820']);
        $form = $set($form, 'editEvents[0][startDate]', ['1820']);
        $form = $set($form, 'editEvents[0][type]', ['/index.php/accumulation']);
        $r = $save('/index.php/informationobject/add', $form);
        $check('alta con campos adicionales', [302, true], [$r['code'], null !== $r['id']]);
        $io2 = (int) $r['id'];
        $created['io'][] = $io2;
        $check('fecha de Acumulación con inicio normalizado', [QubitTerm::ACCUMULATION_ID, '1820-00-00'], array_map(
            static fn ($v) => is_numeric($v) && (string) (int) $v === (string) $v ? (int) $v : $v,
            array_values($q('SELECT type_id, start_date FROM event WHERE object_id = ? AND type_id = ?', [$io2, QubitTerm::ACCUMULATION_ID])[0] ?? [])
        ));

        $check('la descripción tiene sus datos adicionales (notas de publicación, archivero e idioma, identificador alternativo, idiomas, materia, productor, título)', [
            'notes' => [QubitTerm::LANGUAGE_NOTE_ID, QubitTerm::ARCHIVIST_NOTE_ID, QubitTerm::PUBLICATION_NOTE_ID],
            'alternativeIdentifiers' => ['Signatura antigua'],
            'subjects' => [(int) $s1->id],
            'creators' => [(int) $a[2]->id],
            'title' => "Título existente {$run}",
        ], [
            'notes' => array_map('intval', $col('SELECT type_id FROM note WHERE object_id = ? ORDER BY type_id DESC', [$io2])),
            'alternativeIdentifiers' => $col("SELECT name FROM property WHERE object_id = ? AND scope = 'alternativeIdentifiers'", [$io2]),
            'subjects' => array_map('intval', $col('SELECT r.term_id FROM object_term_relation r JOIN term t ON t.id = r.term_id WHERE r.object_id = ? AND t.taxonomy_id = ?', [$io2, QubitTaxonomy::SUBJECT_ID])),
            'creators' => array_map('intval', $col('SELECT actor_id FROM event WHERE object_id = ? AND type_id = ? AND actor_id IS NOT NULL', [$io2, QubitTerm::CREATION_ID])),
            'title' => $i18n($io2, 'title'),
        ]);

        // Caso A: campos adicionales cerrados (no se tocan); solo cambia Contenido.
        $before = $snapshot($io2, ['scope_and_content']);
        $form = $serialize($request($editPath($io2))['body']);
        $form = $set($form, 'scopeAndContent', ['Contenido cambiado (A).']);
        $r = $save($editPath($io2), $form);
        $check('A: guardar cambiando solo Contenido', 302, $r['code']);
        $check('A: Contenido cambiado', 'Contenido cambiado (A).', $i18n($io2, 'scope_and_content'));
        $check('A: todos los demás datos idénticos (adicionales, roles, fechas, notas, relaciones)', [], $diff($before, $snapshot($io2, ['scope_and_content'])));

        // Caso B: se edita un campo adicional; el resto se conserva.
        $before = $snapshot($io2, ['archival_history']);
        $form = $serialize($request($editPath($io2))['body']);
        $form = $set($form, 'archivalHistory', ['Historia archivística editada (B).']);
        $r = $save($editPath($io2), $form);
        $check('B: guardar cambiando un campo adicional', 302, $r['code']);
        $check('B: Historia archivística cambiada', 'Historia archivística editada (B).', $i18n($io2, 'archival_history'));
        $check('B: todos los demás datos idénticos', [], $diff($before, $snapshot($io2, ['archival_history'])));
        $check('Título existente conservado', "Título existente {$run}", $i18n($io2, 'title'));

        echo "== Tipos de fecha ==\n";
        $termUrl = static fn (int $id) => '/index.php/'.$one('SELECT slug FROM slug WHERE object_id = ?', [$id]);
        $creationUrl = $termUrl(QubitTerm::CREATION_ID);
        $accumulationUrl = $termUrl(QubitTerm::ACCUMULATION_ID);
        // Fechas de la descripción: id de evento → [tipo, expresión, inicio, fin]; también eventos sin actor de otros tipos.
        $dates = static function (int $io) use ($q, $remitenteId, $destinatarioId): array {
            $out = [];
            foreach ($q("SELECT e.id, e.type_id, i.date, e.start_date, e.end_date FROM event e LEFT JOIN event_i18n i ON i.id = e.id AND i.culture = 'es'
                WHERE e.object_id = ? AND e.actor_id IS NULL ORDER BY e.id", [$io]) as $r) {
                $out[(int) $r['id']] = [(int) $r['type_id'], $r['date'], $r['start_date'], $r['end_date']];
            }

            return $out;
        };
        // Estado de "Más opciones de fecha" de la fila de un evento en el HTML del formulario: [abierta, aria-expanded, tipo].
        $moreState = static function (string $html, int $eventId): ?array {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
            libxml_clear_errors();
            $xp = new DOMXPath($dom);
            $row = $xp->query("//tr[contains(concat(' ', @class, ' '), ' related_obj_{$eventId} ')]")->item(0);
            if (null === $row) {
                return null;
            }
            $more = $xp->query(".//div[contains(@class, 'ah-date-more')]", $row)->item(0);
            $toggle = $xp->query(".//button[contains(@class, 'ah-date-more-toggle')]", $row)->item(0);
            $selected = $xp->query(".//select[contains(@name, '[type]')]/option[@selected]", $more)->item(0);

            return [!$more->hasAttribute('hidden'), $toggle->getAttribute('aria-expanded'), $selected ? $selected->getAttribute('value') : null];
        };
        // Índice de fila (editEvents[N]) del evento en los pares serializados.
        $rowOf = static function (array $pairs, int $eventId) use ($one): ?int {
            $url = '/index.php/'.$one('SELECT slug FROM slug WHERE object_id = ?', [$eventId]);
            foreach ($pairs as [$name, $value]) {
                if ($value === $url && preg_match('/^editEvents\[(\d+)\]\[id\]$/', $name, $m)) {
                    return (int) $m[1];
                }
            }

            return null;
        };

        // Alta con dos fechas: la primera sin tocar el tipo (la fila vacía del formulario) y otra de Acumulación, como la que
        // añade multiRow.js.
        $form = $serialize($request('/index.php/informationobject/add')['body']);
        $check('fila nueva: el tipo predeterminado es Creación', [$creationUrl], $get($form, 'editEvents[0][type]'));
        $form = $set($form, 'identifier', ["FT-{$run}"]);
        $form = $set($form, 'remitentes[]', [$url($a[1])]);
        $form = $set($form, 'destinatarios[]', [$url($a[3])]);
        $form = $set($form, 'editEvents[0][date]', ['marzo de 1801']);
        $form = array_merge($form, [
            ['editEvents[1][date]', 'ca. 1820'], ['editEvents[1][startDate]', '1820'], ['editEvents[1][endDate]', ''],
            ['editEvents[1][type]', $accumulationUrl],
        ]);
        $r = $save('/index.php/informationobject/add', $form);
        $check('alta con dos fechas', [302, true], [$r['code'], null !== $r['id']]);
        $io3 = (int) $r['id'];
        $created['io'][] = $io3;
        $initial = $dates($io3);
        $ids = array_keys($initial);
        [$creationEvent, $accumulationEvent] = $ids;
        $check('nueva fecha sin elegir tipo → Creación; la otra → Acumulación', [
            [QubitTerm::CREATION_ID, 'marzo de 1801', null, null],
            [QubitTerm::ACCUMULATION_ID, 'ca. 1820', '1820-00-00', null],
        ], array_values($initial));

        // Una fecha de otro tipo existente (Custodia), como las que puede traer una importación, y un evento con actor de otro tipo.
        $custody = new QubitEvent();
        $custody->objectId = $io3;
        $custody->typeId = QubitTerm::CUSTODY_ID;
        $custody->setDate('Custodia hacia 1830', ['culture' => 'es']);
        $custody->sourceCulture = 'es';
        $custody->indexOnSave = false;
        $custody->save();
        $custodyEvent = (int) $custody->id;
        $publication = new QubitEvent();
        $publication->objectId = $io3;
        $publication->typeId = QubitTerm::PUBLICATION_ID;
        $publication->actorId = $a[8]->id;
        $publication->indexOnSave = false;
        $publication->save();

        $edit = $request($editPath($io3))['body'];
        $check('fecha Creación: "Más opciones" cerrada', [false, 'false', $creationUrl], $moreState($edit, $creationEvent));
        $check('fecha Acumulación: "Más opciones" abierta con su tipo', [true, 'true', $accumulationUrl], $moreState($edit, $accumulationEvent));
        $check('fecha Custodia: "Más opciones" abierta con su tipo (no se transforma)', [true, 'true', $termUrl(QubitTerm::CUSTODY_ID)], $moreState($edit, $custodyEvent));
        preg_match_all('/id="(ah-date-more-[^"]+)"/', $edit, $m);
        $check('ids de las expansiones únicos (3 fechas + fila vacía)', 4, count(array_unique($m[1])));

        // Guardar otros cambios (Contenido) con las expansiones tal como llegan: todos los tipos y valores se conservan.
        $before = $snapshot($io3, ['scope_and_content']);
        $datesBefore = $dates($io3);
        $form = $serialize($edit);
        $form = $set($form, 'scopeAndContent', ['Cambio ajeno a las fechas.']);
        $r = $save($editPath($io3), $form);
        $check('guardar otros cambios', 302, $r['code']);
        $check('las tres fechas conservan exactamente tipo, expresión e inicio/fin', $datesBefore, $dates($io3));
        $check('nada más cambió (eventos de rol, Publicación, notas, relaciones...)', [], $diff($before, $snapshot($io3, ['scope_and_content'])));

        // Cambio explícito: Creación → Acumulación y Acumulación → Creación; Custodia sin tocar.
        $form = $serialize($request($editPath($io3))['body']);
        $form = $set($form, "editEvents[{$rowOf($form, $creationEvent)}][type]", [$accumulationUrl]);
        $form = $set($form, "editEvents[{$rowOf($form, $accumulationEvent)}][type]", [$creationUrl]);
        $before = $snapshot($io3);
        $r = $save($editPath($io3), $form);
        $check('guardar cambio de tipos', 302, $r['code']);
        $expected = $datesBefore;
        $expected[$creationEvent][0] = QubitTerm::ACCUMULATION_ID;
        $expected[$accumulationEvent][0] = QubitTerm::CREATION_ID;
        $check('Creación ↔ Acumulación cambiados; expresión, inicio/fin y Custodia intactos', $expected, $dates($io3));
        $after = $snapshot($io3);
        $rolesAndOthers = static fn (array $snap) => array_values(array_filter($snap['event'], static fn ($e) => null !== $e['actor_id']));
        $check('Remitente, Destinatario y Publicación intactos', $rolesAndOthers($before), $rolesAndOthers($after));
        unset($before['event'], $after['event']);
        $check('fuera de los tipos de fecha, nada más cambió', [], $diff($before, $after));

        echo "== Error de validación: se conserva lo enviado ==\n";
        // Campos que el catalogador escribió y que deben volver tal cual cuando el servidor devuelve el formulario con un error
        // (aquí, un Remitente que no es un actor). Los nombres con [] y las filas de Fecha/Observaciones comparan todos sus valores.
        $sentFields = static function (array $pairs): array {
            $keep = [];
            foreach ($pairs as [$name, $value]) {
                if (preg_match('/^(identifier|levelOfDescription|scopeAndContent|extentAndMedium|title|archivalHistory|remitentes\[\]|destinatarios\[\]|placeAccessPoints\[\]|subjectAccessPoints\[\]|creators\[\])$/', $name)
                    || preg_match('/^editEvents\[\d+\]\[(id|date|startDate|endDate|type)\]$/', $name)
                    || preg_match('/^(isadNotes|isadPublicationNotes|isadArchivistsNotes)\[\d+\]\[(id|content)\]$/', $name)
                    || preg_match('/^alternativeIdentifiers\[\d+\]\[(id|label|identifier)\]$/', $name)
                    || preg_match('/^updateChildLevels\[\d+\]\[(identifier|levelOfDescription|title|date)\]$/', $name)) {
                    $keep[] = [$name, $value];
                }
            }
            // Se compara el conjunto: el orden de los pares en el HTML no es un dato del usuario.
            sort($keep);

            return $keep;
        };
        // Filas de Fecha y Observaciones sin contenido (la fila vacía final) no son datos del usuario.
        $withoutEmptyRows = static function (array $pairs): array {
            $rows = [];
            $valueFields = [
                'editEvents' => ['date', 'startDate', 'endDate'], 'isadNotes' => ['content'], 'isadPublicationNotes' => ['content'],
                'isadArchivistsNotes' => ['content'], 'alternativeIdentifiers' => ['label', 'identifier'],
                'updateChildLevels' => ['identifier', 'levelOfDescription', 'title', 'date'],
            ];
            $pattern = '/^('.implode('|', array_keys($valueFields)).')\[(\d+)\]\[(\w+)\]$/';
            foreach ($pairs as [$name, $value]) {
                if (preg_match($pattern, $name, $m)) {
                    $rows[$m[1]][$m[2]][$m[3]] = $value;
                }
            }
            $out = [];
            foreach ($pairs as [$name, $value]) {
                if (preg_match($pattern, $name, $m)) {
                    $row = $rows[$m[1]][$m[2]];
                    $filled = '' !== ($row['id'] ?? '') || '' !== implode('', array_map(static fn ($f) => $row[$f] ?? '', $valueFields[$m[1]]));
                    if (!$filled) {
                        continue;
                    }
                }
                $out[] = [$name, $value];
            }

            return $out;
        };
        $invalid = "/index.php/no-existe-{$run}";

        // Alta.
        $count = (int) $one('SELECT COUNT(*) FROM information_object');
        $form = $serialize($request('/index.php/informationobject/add')['body']);
        $form = $set($form, 'identifier', ["ERR-{$run} (Ind. C II -24 mn)"]);
        $form = $set($form, 'levelOfDescription', ['/index.php/'.QubitTerm::getById($seccionId)->slug]);
        $form = $set($form, 'remitentes[]', [$url($a[1]), $invalid]);
        $form = $set($form, 'destinatarios[]', [$url($a[3]), $url($a[4])]);
        $form = $set($form, 'scopeAndContent', ["Contenido sin guardar.\nCon dos líneas."]);
        $form = $set($form, 'placeAccessPoints[]', [$url($p1), $url($p2)]);
        $form = $set($form, 'editEvents[0][date]', ['12 de marzo de 1810']);
        $form = $set($form, 'editEvents[0][startDate]', ['1810-03-12']);
        $form = $set($form, 'editEvents[0][endDate]', ['1810-03-12']);
        $form = array_merge($form, [
            ['editEvents[1][date]', 'ca. 1820'], ['editEvents[1][startDate]', '1820'], ['editEvents[1][endDate]', '1825'],
            ['editEvents[1][type]', $accumulationUrl],
        ]);
        $form = $set($form, 'extentAndMedium', ['4 folios']);
        $form = $set($form, 'isadNotes[0][content]', ['Manuscrito. Original']);
        $form = array_merge($form, [['isadNotes[1][type]', (string) QubitTerm::GENERAL_NOTE_ID], ['isadNotes[1][content]', "Segunda observación\ncon salto de línea."]]);
        $form = $set($form, 'title', ["Título sin guardar {$run}"]);
        $form = $set($form, 'archivalHistory', ['Historia sin guardar.']);
        $form = $set($form, 'subjectAccessPoints[]', [$url($s1)]);
        $form = $set($form, 'creators[]', [$url($a[2])]);
        $form = $set($form, 'isadPublicationNotes[0][content]', ['Nota de publicación sin guardar.']);
        $form = $set($form, 'isadArchivistsNotes[0][content]', ['Nota del archivero sin guardar.']);
        $form = $set($form, 'alternativeIdentifiers[0][label]', ['Signatura antigua']);
        $form = $set($form, 'alternativeIdentifiers[0][identifier]', ['C II 24']);
        $form = $set($form, 'updateChildLevels[0][identifier]', ["HIJO-{$run}"]);
        $form = $set($form, 'updateChildLevels[0][title]', ['Nivel inferior sin guardar']);
        $form = $set($form, 'updateChildLevels[0][levelOfDescription]', [(string) QubitTerm::getById($seccionId)->id]);
        $r = $save('/index.php/informationobject/add', $form);
        $check('alta con error: vuelve el formulario con el error', [200, true], [$r['code'], false !== strpos($r['body'], 'remitentes-errors')]);
        $check('alta con error: no se creó nada', $count, (int) $one('SELECT COUNT(*) FROM information_object'));
        $returned = $serialize($r['body']);
        $check('alta con error: la expansión de la fecha de Acumulación vuelve abierta, la de Creación cerrada', [[true, 'true', $accumulationUrl], [false, 'false', $creationUrl]], array_map(
            static function (int $row) use ($r) {
                preg_match('#<button\s+type="button"\s+class="ah-date-more-toggle"\s+aria-expanded="(\w+)"\s+aria-controls="ah-date-more-'.$row.'">.*?<div class="ah-date-more" id="ah-date-more-'.$row.'"( hidden)?>.*?<option value="([^"]+)" selected#s', $r['body'], $m);

                return [!isset($m[2]) || '' === $m[2], $m[1] ?? null, $m[3] ?? null];
            },
            [1, 0]
        ));
        $expected = $withoutEmptyRows($sentFields($set($form, 'remitentes[]', [$url($a[1])])));
        $back = $withoutEmptyRows($sentFields($serialize($r['body'])));
        $check('alta con error: todo lo enviado vuelve igual (salvo el nombre inválido)', $expected, $back);
        $check('alta con error: la fila vacía para seguir añadiendo fechas sigue presente', true, in_array('editEvents[2][date]', array_column($returned, 0), true));

        // Reenviar el formulario devuelto tal cual (el catalogador corrige el error y guarda): se guarda lo que había escrito.
        $r = $save('/index.php/informationobject/add', $returned);
        $check('alta reenviada tras el error: se guarda', [302, true], [$r['code'], null !== $r['id']]);
        $io4 = (int) $r['id'];
        $created['io'][] = $io4;
        $created['io'][] = (int) $one('SELECT id FROM information_object WHERE identifier = ?', ["HIJO-{$run}"]);
        $check('alta reenviada: fechas con sus tipos, roles, lugares, notas, identificador alternativo y nivel inferior', [
            'dates' => [[QubitTerm::CREATION_ID, '12 de marzo de 1810', '1810-03-12', '1810-03-12'], [QubitTerm::ACCUMULATION_ID, 'ca. 1820', '1820-00-00', '1825-00-00']],
            'remitentes' => [(int) $a[1]->id],
            'destinatarios' => [(int) $a[3]->id, (int) $a[4]->id],
            'places' => [(int) $p1->id, (int) $p2->id],
            'notes' => [
                [QubitTerm::PUBLICATION_NOTE_ID, 'Nota de publicación sin guardar.'], [QubitTerm::ARCHIVIST_NOTE_ID, 'Nota del archivero sin guardar.'],
                [QubitTerm::GENERAL_NOTE_ID, 'Manuscrito. Original'], [QubitTerm::GENERAL_NOTE_ID, "Segunda observación\ncon salto de línea."],
            ],
            'altId' => ['Signatura antigua'],
            'child' => 'Nivel inferior sin guardar',
        ], [
            'dates' => array_values($dates($io4)),
            'remitentes' => $roleActors($io4, $remitenteId),
            'destinatarios' => $roleActors($io4, $destinatarioId),
            'places' => array_map('intval', $col('SELECT r.term_id FROM object_term_relation r JOIN term t ON t.id = r.term_id WHERE r.object_id = ? AND t.taxonomy_id = ? ORDER BY r.term_id', [$io4, QubitTaxonomy::PLACE_ID])),
            'notes' => array_map(static fn ($n) => [(int) $n['type_id'], $n['content']], $q("SELECT n.type_id, i.content FROM note n JOIN note_i18n i ON i.id = n.id WHERE n.object_id = ? ORDER BY n.type_id, n.id", [$io4])),
            'altId' => $col("SELECT name FROM property WHERE object_id = ? AND scope = 'alternativeIdentifiers'", [$io4]),
            'child' => $one("SELECT i.title FROM information_object io JOIN information_object_i18n i ON i.id = io.id WHERE io.parent_id = ? AND io.identifier = ?", [$io4, "HIJO-{$run}"]),
        ]);

        // Edición: se modifican filas existentes (fecha y observación), se añaden otras y se envía con el error.
        $form = $serialize($request($editPath($io3))['body']);
        $form = $set($form, 'remitentes[]', array_merge($get($form, 'remitentes[]'), [$invalid]));
        $form = $set($form, 'scopeAndContent', ['Edición sin guardar.']);
        $existingRow = $rowOf($form, $creationEvent);
        $form = $set($form, "editEvents[{$existingRow}][date]", ['expresión editada sin guardar']);
        $form = $set($form, "editEvents[{$existingRow}][type]", [$creationUrl]);
        $newRow = count(array_filter($form, static fn ($p) => preg_match('/^editEvents\[\d+\]\[date\]$/', $p[0]))) - 1;
        $form = $set($form, "editEvents[{$newRow}][date]", ['fecha nueva sin guardar']);
        $form = $set($form, "editEvents[{$newRow}][startDate]", ['1850']);
        $form = $set($form, "editEvents[{$newRow}][type]", [$accumulationUrl]);
        $form = $set($form, 'isadNotes[0][content]', ['Observación nueva sin guardar']);
        $datesBefore = $dates($io3);
        $r = $save($editPath($io3), $form);
        $check('edición con error: vuelve el formulario con el error', [200, true], [$r['code'], false !== strpos($r['body'], 'remitentes-errors')]);
        $expected = $withoutEmptyRows($sentFields($set($form, 'remitentes[]', array_values(array_diff($get($form, 'remitentes[]'), [$invalid])))));
        $back = $withoutEmptyRows($sentFields($serialize($r['body'])));
        $check('edición con error: todo lo enviado vuelve igual (filas existentes editadas y nuevas incluidas)', $expected, $back);
        $check('edición con error: no se guardó nada de las fechas', $datesBefore, $dates($io3));
        $r = $save($editPath($io3), $serialize($r['body']));
        $check('edición reenviada tras el error: se guarda', 302, $r['code']);
        $expected = $datesBefore;
        $expected[$creationEvent][1] = 'expresión editada sin guardar';
        $expected[$creationEvent][0] = QubitTerm::CREATION_ID;
        $after = $dates($io3);
        $newEvents = array_diff_key($after, $datesBefore);
        $check('edición reenviada: la fila existente se actualiza en su mismo evento y la nueva se crea una sola vez', [
            $expected, [[QubitTerm::ACCUMULATION_ID, 'fecha nueva sin guardar', '1850-00-00', null]],
        ], [array_intersect_key($after, $datesBefore), array_values($newEvents)]);
        $check('edición reenviada: observación nueva guardada', ['Observación nueva sin guardar'], $col('SELECT i.content FROM note n JOIN note_i18n i ON i.id = n.id WHERE n.object_id = ? AND n.type_id = ?', [$io3, QubitTerm::GENERAL_NOTE_ID]));

        echo "== Jerarquía: alta como hijo (flujo nativo ?parent=) ==\n";
        // Padre creado por el propio formulario; el hijo entra por el enlace nativo "Agregar nuevo" de su ficha.
        $form = $serialize($request('/index.php/informationobject/add')['body']);
        $form = $set($form, 'identifier', ["PADRE-{$run}"]);
        $r = $save('/index.php/informationobject/add', $form);
        $parentIo = (int) $r['id'];
        $created['io'][] = $parentIo;
        $parentSlug = $one('SELECT slug FROM slug WHERE object_id = ?', [$parentIo]);
        $check('la ficha del padre enlaza al alta de un hijo', true, false !== strpos($request("/index.php/{$parentSlug}")['body'], "informationobject/add?parent={$parentSlug}"));
        $addChild = $request('/index.php/informationobject/add?parent='.rawurlencode($parentSlug));
        $form = $serialize($addChild['body']);
        $check('alta como hijo: el formulario lleva el padre (hidden nativo `parent`) y el token CSRF', [[$parentSlug], 1], [$get($form, 'parent'), count($get($form, '_csrf_token'))]);
        $form = $set($form, 'identifier', ["HIJO-{$run}-B"]);
        $form = $set($form, 'scopeAndContent', ['Hijo creado desde el padre.']);
        $form = $set($form, 'remitentes[]', [$url($a[1])]);
        $r = $save('/index.php/informationobject/add?parent='.rawurlencode($parentSlug), $form);
        $child = (int) $r['id'];
        $created['io'][] = $child;
        $check('alta como hijo: parent_id = id del padre', $parentIo, (int) $one('SELECT parent_id FROM information_object WHERE id = ?', [$child]));
        $form = $serialize($request($editPath($child))['body']);
        $check('edición del hijo: lleva serialNumber y token CSRF, y no un `parent` que pudiera moverlo', [1, 1, []], [
            count($get($form, 'serialNumber')), count($get($form, '_csrf_token')), $get($form, 'parent'),
        ]);
        $form = $set($form, 'scopeAndContent', ['Contenido del hijo editado.']);
        $r = $save($editPath($child), $form);
        $check('edición del hijo: se guarda y parent_id no cambia', [302, $parentIo, 'Contenido del hijo editado.'], [
            $r['code'], (int) $one('SELECT parent_id FROM information_object WHERE id = ?', [$child]), $i18n($child, 'scope_and_content'),
        ]);

        echo "== Taxonomías ==\n";
        $check('niveles de descripción y tipos de evento sin cambios', $termsBefore, $termsFp());
    } catch (Throwable $e) {
        ++$fails;
        echo 'FAIL  excepción: '.get_class($e).': '.$e->getMessage()."\n";
    } finally {
        foreach (array_reverse($created['io']) as $id) {
            if (null !== $o = QubitInformationObject::getById($id)) {
                $o->delete();
            }
        }
        foreach ($created['actor'] as $id) {
            if (null !== $o = QubitActor::getById($id)) {
                $o->delete();
            }
        }
        foreach ($created['term'] as $id) {
            if (null !== $o = QubitTerm::getById($id)) {
                $o->delete();
            }
        }
        $left = (int) $one('SELECT COUNT(*) FROM actor_i18n WHERE authorized_form_of_name LIKE ?', ["%{$run}"])
            + (int) $one('SELECT COUNT(*) FROM term_i18n WHERE name LIKE ?', ["%{$run}"])
            + (int) $one('SELECT COUNT(*) FROM information_object WHERE identifier LIKE ?', ["%{$run}%"]);
        echo 0 === $left ? "limpieza: datos de prueba eliminados\n" : "WARN  limpieza: quedan {$left} filas de prueba\n";
    }

    echo 0 === $fails ? "RESULTADO: OK\n" : "RESULTADO: {$fails} FAIL\n";

    exit(0 === $fails ? 0 : 1);
})();
