<?php

/*
 * RECONCILE de los términos PROJECT-MANAGED de taxonomías de AtoM: garantiza que EXISTAN los términos propios del proyecto.
 *
 *   Nivel de descripción (taxonomía 34)  archivo-historico:seccion       Sección / Section
 *   Tipo de evento (taxonomía 40)        archivo-historico:remitente     Remitente / Remitente
 *   Tipo de evento (taxonomía 40)        archivo-historico:destinatario  Destinatario / Destinatario
 *
 * El nombre `en` existe porque AtoM lo usa técnicamente (exportación), no como traducción semántica: Remitente y Destinatario son
 * categorías locales del Archivo y no está demostrado que equivalgan a sender/recipient, así que conservan el nombre local.
 *
 * Se ejecuta con `php symfony tools:run` (contexto Symfony/Propel de AtoM ya inicializado) desde config/atom/reconcile.sh,
 * dentro del servicio `reconcile`. NO gestiona las taxonomías: el proyecto solo es dueño de SUS términos. Los términos estándar de
 * AtoM y los creados por usuarios no se leen para decidir nada salvo para detectar homónimos, y nunca se modifican.
 *
 * Identidad: taxonomía + `code` (igualdad binaria). Nunca por id (autoincremental) ni por nombre.
 *
 * Por cada término, ANTES de escribir nada (todo o nada):
 *   - nuestro code en otra taxonomía, o repetido                     → STOP (no reconciliable)
 *   - existe con estado canónico (nombres es/en, padre = raíz, cultura fuente es) → no-op
 *   - existe pero con nombres es/en, padre o cultura fuente distintos → STOP: es NUESTRO estado alterado; no se autocorrige ni se
 *     sobrescribe (alguien lo cambió y el proyecto no decide a ciegas qué valor gana)
 *   - existe, canónico, y hay un homónimo AJENO aparecido después (p. ej. creado por una importación) → solo AVISO: la identidad del
 *     nuestro sigue siendo inequívoca por taxonomía + code
 *   - no existe y hay un homónimo SIN nuestro code en la taxonomía   → STOP: no se adopta ni se crea un duplicado
 *     (homónimo = algún nombre, en cualquier cultura, igual a uno de los esperados según la collation de la BD: sin distinguir
 *     mayúsculas ni acentos; "seccion" o "SECTION" también cuentan)
 *   - no existe y sin conflicto                                       → se crea (cultura fuente es; nombres es + en; bajo la raíz)
 *
 * Postcondición (releyendo la BD): cada término existe exactamente una vez, en su taxonomía; los creados en esta ejecución tienen
 * los nombres esperados; y ningún otro término cambió (id, taxonomía, code, padre, cultura fuente y nombres de TODOS los demás
 * términos). lft/rgt quedan fuera de esa huella: insertar un término renumera el nested set de AtoM, como cualquier alta.
 *
 * Idempotente: si los tres existen, no escribe nada. Supone un único writer (gate pre-start), como el resto del reconcile.
 * Nunca borra términos. No distingue "nunca provisionado" de "borrado después": si se borra, el siguiente reconcile lo recrea con
 * otro id. Recrear NO recupera lo perdido al borrar: borrar un nivel de descripción deja en NULL el nivel de las descripciones que lo
 * usaban, y borrar un tipo de evento ELIMINA en cascada los eventos de ese tipo (p. ej. todos los Remitente). Protegerlos frente a
 * borrados administrativos queda pendiente (debe resolverse antes de entregar cuentas reales de catalogación).
 *
 * Exit: 0 éxito (con o sin avisos de homónimos ajenos); 77 no se pudo observar; 78 no reconciliable (detectado ANTES de escribir);
 *       79 la escritura falló; 80 postcondición no satisfecha.
 */
(static function (): void {
    $sourceCulture = 'es';
    $desired = [
        ['code' => 'archivo-historico:seccion', 'taxonomy' => QubitTaxonomy::LEVEL_OF_DESCRIPTION_ID, 'names' => ['es' => 'Sección', 'en' => 'Section']],
        ['code' => 'archivo-historico:remitente', 'taxonomy' => QubitTaxonomy::EVENT_TYPE_ID, 'names' => ['es' => 'Remitente', 'en' => 'Remitente']],
        ['code' => 'archivo-historico:destinatario', 'taxonomy' => QubitTaxonomy::EVENT_TYPE_ID, 'names' => ['es' => 'Destinatario', 'en' => 'Destinatario']],
    ];
    $codes = array_column($desired, 'code');

    $log = static function (string $message): void {
        echo "reconcile: terms: {$message}\n";
    };
    $fail = static function (int $code, string $message) use ($log): never {
        $log($message);

        exit($code);
    };

    // Estado de UN término deseado: filas con nuestro code (cualquier taxonomía), sus nombres y los homónimos ajenos.
    $observe = static function (array $term): array {
        $rows = QubitPdo::fetchAll(
            'SELECT id, taxonomy_id, parent_id, source_culture FROM term WHERE code = ? COLLATE utf8mb4_bin ORDER BY id',
            [$term['code']]
        );
        $names = [];
        if (1 === count($rows)) {
            foreach (QubitPdo::fetchAll('SELECT culture, name FROM term_i18n WHERE id = ?', [$rows[0]->id]) as $r) {
                $names[$r->culture] = $r->name;
            }
        }
        $homonyms = QubitPdo::fetchAll(
            'SELECT DISTINCT t.id FROM term t JOIN term_i18n i ON i.id = t.id '
            .'WHERE t.taxonomy_id = ? AND (t.code IS NULL OR t.code <> ? COLLATE utf8mb4_bin) AND i.name IN (?, ?) ORDER BY t.id',
            [$term['taxonomy'], $term['code'], $term['names']['es'], $term['names']['en']],
            ['fetchMode' => PDO::FETCH_COLUMN]
        );

        return ['rows' => $rows, 'names' => $names, 'homonyms' => $homonyms];
    };

    // Huella de todos los términos que NO son nuestros (sin lft/rgt). Hasheada en PHP: sin límites de GROUP_CONCAT.
    $foreign = static function () use ($codes): string {
        $marks = implode(',', array_fill(0, count($codes), '?'));
        $rows = QubitPdo::fetchAll(
            'SELECT t.id, t.taxonomy_id, t.code, t.parent_id, t.source_culture, i.culture, i.name FROM term t '
            ."LEFT JOIN term_i18n i ON i.id = t.id WHERE t.code IS NULL OR t.code NOT IN ({$marks}) ORDER BY t.id, i.culture",
            $codes,
            ['fetchMode' => PDO::FETCH_NUM]
        );

        return hash('sha256', json_encode($rows));
    };

    try {
        $before = array_map($observe, $desired);
        $foreignBefore = $foreign();
    } catch (Throwable $e) {
        $fail(77, 'no se pudo observar el estado ('.get_class($e).')');
    }

    // Clasificación y STOP antes de cualquier escritura.
    $conflicts = [];
    $missing = [];
    foreach ($desired as $k => $term) {
        $state = $before[$k];
        $rows = $state['rows'];
        $label = "{$term['code']} (taxonomía {$term['taxonomy']})";
        if (count($rows) > 1) {
            $conflicts[] = "{$label}: el code aparece ".count($rows).' veces (ids '.implode(',', array_column($rows, 'id')).')';
        } elseif (1 === count($rows) && (int) $rows[0]->taxonomy_id !== $term['taxonomy']) {
            $conflicts[] = "{$label}: el code existe en otra taxonomía ({$rows[0]->taxonomy_id}, id {$rows[0]->id})";
        } elseif (0 === count($rows)) {
            if ([] !== $state['homonyms']) {
                $conflicts[] = "{$label}: falta, y ya hay término(s) homónimo(s) sin nuestro code (ids ".implode(',', $state['homonyms']).'); no se adopta ni se duplica';
            } else {
                $missing[] = $k;
            }
        } else {
            $id = $rows[0]->id;
            $drift = [];
            foreach ($term['names'] as $culture => $name) {
                $actual = $state['names'][$culture] ?? null;
                if ($name !== $actual) {
                    $drift[] = "nombre {$culture} ".var_export($actual, true)." (esperado '{$name}')";
                }
            }
            if ((int) $rows[0]->parent_id !== QubitTerm::ROOT_ID) {
                $drift[] = "padre {$rows[0]->parent_id} (esperado la raíz ".QubitTerm::ROOT_ID.')';
            }
            if ($sourceCulture !== $rows[0]->source_culture) {
                $drift[] = 'cultura fuente '.var_export($rows[0]->source_culture, true)." (esperada '{$sourceCulture}')";
            }
            if ([] !== $drift) {
                $conflicts[] = "{$label} id {$id}: nuestro término fue alterado: ".implode('; ', $drift).'; no se autocorrige';

                continue;
            }
            if ([] !== $state['homonyms']) {
                $log("AVISO {$label} id {$id}: hay homónimo(s) sin nuestro code (ids ".implode(',', $state['homonyms']).'); no se tocan');
            }
            $log("{$label}: ya existe (id {$id}); sin cambios");
        }
    }
    if ([] !== $conflicts) {
        foreach ($conflicts as $c) {
            $log("CONFLICTO {$c}");
        }
        $fail(78, 'NO RECONCILIABLE: no se escribió nada');
    }

    $created = [];
    foreach ($missing as $k) {
        $term = $desired[$k];
        try {
            $new = new QubitTerm();
            $new->taxonomyId = $term['taxonomy'];
            $new->parentId = QubitTerm::ROOT_ID;
            $new->code = $term['code'];
            $new->sourceCulture = $sourceCulture;
            foreach ($term['names'] as $culture => $name) {
                $new->setName($name, ['culture' => $culture]);
            }
            $new->save();
        } catch (Throwable $e) {
            $fail(79, "{$term['code']}: la escritura falló (".get_class($e).')');
        }
        $created[$k] = true;
        $log("{$term['code']} (taxonomía {$term['taxonomy']}): creado (id {$new->id})");
    }

    if ([] === $created) {
        $log('OK: los términos del proyecto ya existían; sin escritura');

        return;
    }

    // Postcondición, releyendo la BD.
    try {
        $after = array_map($observe, $desired);
        $foreignAfter = $foreign();
    } catch (Throwable $e) {
        $fail(80, 'POSTCONDICIÓN NO VERIFICABLE: no se pudo releer ('.get_class($e).')');
    }
    foreach ($desired as $k => $term) {
        $rows = $after[$k]['rows'];
        if (1 !== count($rows) || (int) $rows[0]->taxonomy_id !== $term['taxonomy']) {
            $fail(80, "POSTCONDICIÓN NO SATISFECHA: {$term['code']} no existe exactamente una vez en la taxonomía {$term['taxonomy']}");
        }
        if (isset($created[$k])) {
            $actual = array_intersect_key($after[$k]['names'], $term['names']);
            ksort($actual);
            $expected = $term['names'];
            ksort($expected);
            if ($actual !== $expected || (int) $rows[0]->parent_id !== QubitTerm::ROOT_ID || $sourceCulture !== $rows[0]->source_culture) {
                $fail(80, "POSTCONDICIÓN NO SATISFECHA: {$term['code']} no quedó con los nombres, el padre o la cultura fuente esperados");
            }
        }
    }
    if ($foreignBefore !== $foreignAfter) {
        $fail(80, 'POSTCONDICIÓN NO SATISFECHA: cambió algún término que no es del proyecto');
    }
    $log('OK: términos creados y verificados (los demás términos intactos)');
})();
