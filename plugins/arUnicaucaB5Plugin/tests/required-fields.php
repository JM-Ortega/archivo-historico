<?php

/*
 * Prueba de integración: campos obligatorios del formulario de catalogación (KAN-164, casos CD-02 y CD-09).
 *
 * AtoM acepta campos vacíos y solo avisa después de guardar. Aquí se comprueba la regla del Archivo (servidor):
 *   - unidad documental (y descripciones sin nivel de agrupación): la signatura es obligatoria;
 *   - agrupación (Fondo, Sección, Serie, Subserie): al menos el título o la signatura;
 *   - un guardado rechazado no crea ni cambia nada, y el formulario vuelve con el mensaje y lo que se escribió.
 *
 * Conduce el formulario real por HTTP contra `nginx` con el admin DEV y verifica por SQL. Todo lo que crea (prefijo ZZ-PRUEBA)
 * se borra al terminar, también si falla. Se ejecuta con tests/test-required-fields.sh. Exit: 0 todo PASS; 1 algún FAIL.
 *
 * Entorno: CATALOGING_TEST_URL (por defecto http://nginx), CATALOGING_TEST_EMAIL, CATALOGING_TEST_PASSWORD.
 */
(static function (): void {
    $base = rtrim(getenv('CATALOGING_TEST_URL') ?: 'http://nginx', '/');
    $email = getenv('CATALOGING_TEST_EMAIL') ?: 'admin@unicauca.edu.co';
    $password = getenv('CATALOGING_TEST_PASSWORD') ?: 'admin_dev_12345';
    $run = 'ZZ-PRUEBA-'.bin2hex(random_bytes(3));
    $fails = 0;
    $created = [];

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
    $one = static fn (string $sql, array $params = []) => QubitPdo::fetchColumn($sql, $params);
    $count = static fn () => (int) $one('SELECT COUNT(*) FROM information_object');

    // --- HTTP (un único handle: su motor de cookies mantiene la sesión) --------------------------------------------------
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 120]);
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
        $location = preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $size), $m) ? $m[1] : null;

        return ['code' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => substr($raw, $size), 'location' => $location];
    };
    $encode = static fn (array $pairs) => implode('&', array_map(static fn ($p) => rawurlencode($p[0]).'='.rawurlencode($p[1]), $pairs));

    // Pares [nombre, valor] que un navegador enviaría desde el formulario (sin JavaScript).
    $serialize = static function (string $html): array {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        $form = $xp->query("//form[@id='editForm']")->item(0);
        if (null === $form) {
            throw new RuntimeException('no hay form#editForm');
        }
        $pairs = [];
        foreach ($xp->query('.//input|.//textarea|.//select', $form) as $el) {
            $name = $el->getAttribute('name');
            if ('' === $name || $el->hasAttribute('disabled')) {
                continue;
            }
            if ('input' === $el->nodeName) {
                $type = strtolower($el->getAttribute('type') ?: 'text');
                if (in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) {
                    continue;
                }
                if (in_array($type, ['checkbox', 'radio'], true)) {
                    if ($el->hasAttribute('checked')) {
                        $pairs[] = [$name, $el->hasAttribute('value') ? $el->getAttribute('value') : 'on'];
                    }

                    continue;
                }
                $pairs[] = [$name, $el->getAttribute('value')];
            } elseif ('textarea' === $el->nodeName) {
                $pairs[] = [$name, preg_replace('/^\r?\n/', '', $el->textContent)];
            } else {
                $options = $xp->query('.//option', $el);
                $selected = null;
                foreach ($options as $o) {
                    if ($o->hasAttribute('selected')) {
                        $selected = $o->getAttribute('value');
                    }
                }
                if (null === $selected && 0 < $options->length && !$el->hasAttribute('multiple')) {
                    $selected = $options->item(0)->getAttribute('value');
                }
                if (null !== $selected) {
                    $pairs[] = [$name, $selected];
                }
            }
        }

        return $pairs;
    };
    $set = static function (array $pairs, string $name, string $value): array {
        $pairs = array_values(array_filter($pairs, static fn ($p) => $p[0] !== $name));
        $pairs[] = [$name, $value];

        return $pairs;
    };
    $levelValue = static function (string $html, string $label): string {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        foreach ((new DOMXPath($dom))->query("//select[@name='levelOfDescription']/option") as $o) {
            if (trim($o->textContent) === $label) {
                return $o->getAttribute('value');
            }
        }
        throw new RuntimeException("no hay nivel «{$label}» en el formulario");
    };
    $idBySlugLocation = static function (?string $location) use ($one): ?int {
        return null === $location ? null : ((int) $one('SELECT object_id FROM slug WHERE slug = ?', [basename(parse_url($location, PHP_URL_PATH))]) ?: null);
    };
    $identifierOf = static fn (int $id) => $one('SELECT identifier FROM information_object WHERE id = ?', [$id]);
    $titleOf = static fn (int $id) => $one("SELECT title FROM information_object_i18n WHERE id = ? AND culture = 'es'", [$id]);

    // Todo lo que aparezca con un id mayor que este lo creó esta prueba (también lo que un defecto guarde sin que se espere).
    $maxIdBefore = (int) $one('SELECT MAX(id) FROM information_object');

    try {
        echo "== Login ==\n";
        $login = $request('/index.php/user/login');
        $token = preg_match('/name="_csrf_token" value="([^"]+)"/', $login['body'], $m) ? $m[1] : '';
        $r = $request('/index.php/user/login', $encode([['_csrf_token', (string) $token], ['email', $email], ['password', $password]]));
        $check('login de admin', 302, $r['code']);

        $addHtml = $request('/index.php/informationobject/add')['body'];
        $form = $serialize($addHtml);
        $unit = $levelValue($addHtml, 'Unidad documental simple');
        $fondo = $levelValue($addHtml, 'Fondo');
        $sinNivel = '';
        $add = static fn (array $pairs) => $request('/index.php/informationobject/add', $pairs ? implode('&', array_map(static fn ($p) => rawurlencode($p[0]).'='.rawurlencode($p[1]), $pairs)) : '');
        $inputRequired = static fn (string $html) => 1 === preg_match('/<input[^>]*name="identifier"[^>]*\srequired\b/i', $html);

        echo "== La pantalla no cambia: ningún campo se marca «required» en el navegador ==\n";
        $check('el campo Signatura no lleva el atributo required (el aviso viene del servidor)', false, $inputRequired($addHtml));

        echo "== Unidad documental sin signatura (CD-02) ==\n";
        $n0 = $count();
        $typed = "Contenido que debe conservarse {$run}";
        $pairs = $set($set($set($form, 'levelOfDescription', $unit), 'identifier', ''), 'scopeAndContent', $typed);
        $r = $add($pairs);
        $check('no se guarda: el formulario vuelve (200, sin redirigir)', [200, null], [$r['code'], $r['location']]);
        $check('el mensaje dice que falta la signatura', true, false !== strpos($r['body'], 'Escribe la signatura de la unidad documental.'));
        $check('lo escrito se conserva en el formulario', true, false !== strpos($r['body'], $typed));
        $check('no se creó ninguna descripción', $n0, $count());
        $r = $add($set($set($form, 'levelOfDescription', $unit), 'identifier', '   '));
        $check('una signatura de solo espacios también se rechaza', [200, $n0], [$r['code'], $count()]);

        echo "== Unidad documental con signatura (control: lo válido se sigue guardando) ==\n";
        $sig = "{$run} (Ind. T I -1 a)";
        $r = $add($set($set($form, 'levelOfDescription', $unit), 'identifier', $sig));
        $id = $idBySlugLocation($r['location']);
        $check('se guarda y redirige a la ficha', true, 302 === $r['code'] && null !== $id);
        if (null !== $id) {
            $created[] = $id;
        }
        $check('la signatura queda guardada', $sig, null === $id ? null : $identifierOf($id));
        $check('el título es la signatura (KAN-158)', $sig, null === $id ? null : $titleOf($id));

        echo "== Editar vaciando la signatura (CD-09) ==\n";
        if (null !== $id) {
            $editPath = '/index.php/'.$one('SELECT slug FROM slug WHERE object_id = ?', [$id]).'/edit';
            $editHtml = $request($editPath)['body'];
            $check('el formulario de edición tampoco lleva required', false, $inputRequired($editHtml));
            $r = $request($editPath, $encode($set($serialize($editHtml), 'identifier', '')));
            $check('no se guarda: el formulario vuelve (200, sin redirigir)', [200, null], [$r['code'], $r['location']]);
            $check('el mensaje dice que falta la signatura', true, false !== strpos($r['body'], 'Escribe la signatura de la unidad documental.'));
            $check('la BD conserva la última signatura válida', $sig, $identifierOf($id));
            $check('y el título que tenía', $sig, $titleOf($id));
        }

        echo "== Agrupación (Fondo) ==\n";
        $n0 = $count();
        $r = $add($set($set($form, 'levelOfDescription', $fondo), 'identifier', ''));
        $check('sin título ni signatura: no se guarda', [200, null, $n0], [$r['code'], $r['location'], $count()]);
        $check('el mensaje pide el título', true, false !== strpos($r['body'], 'Escribe el título de la descripción'));
        $r = $add($set($set($set($form, 'levelOfDescription', $fondo), 'identifier', "{$run}-F1"), 'title', ''));
        $idF1 = $idBySlugLocation($r['location']);
        $check('solo con signatura sí se guarda (como las fichas del catálogo cargado)', true, 302 === $r['code'] && null !== $idF1);
        if (null !== $idF1) {
            $created[] = $idF1;
        }
        $r = $add($set($set($set($form, 'levelOfDescription', $fondo), 'identifier', ''), 'title', "Fondo de prueba {$run}"));
        $idF2 = $idBySlugLocation($r['location']);
        $check('solo con título sí se guarda', true, 302 === $r['code'] && null !== $idF2);
        if (null !== $idF2) {
            $created[] = $idF2;
        }

        echo "== Título solo con espacios en una agrupación (revisión de código) ==\n";
        $n0 = $count();
        $r = $add($set($set($set($form, 'levelOfDescription', $fondo), 'identifier', ''), 'title', '   '));
        $check('un título de solo espacios cuenta como vacío: no se guarda', [200, null, $n0], [$r['code'], $r['location'], $count()]);
        $check('el mensaje pide el título', true, false !== strpos($r['body'], 'Escribe el título de la descripción'));

        echo "== Cambiar a «sin nivel» una ficha que ya existe (revisión de código) ==\n";
        if (null !== $idF2) {
            $levelOf = static fn (int $x) => $one('SELECT level_of_description_id FROM information_object WHERE id = ?', [$x]);
            $levelBefore = $levelOf($idF2);
            $editF2 = '/index.php/'.$one('SELECT slug FROM slug WHERE object_id = ?', [$idF2]).'/edit';
            $htmlF2 = $request($editF2)['body'];
            $r = $request($editF2, $encode($set($set($serialize($htmlF2), 'levelOfDescription', $sinNivel), 'identifier', '')));
            $check('sin nivel y sin identificador: se rechaza aunque la ficha ya fuera un Fondo', [200, null], [$r['code'], $r['location']]);
            $check('el mensaje pide el identificador', true, false !== strpos($r['body'], 'Escribe el identificador de la descripción.'));
            $check('la ficha conserva su nivel', $levelBefore, $levelOf($idF2));
            $r = $request($editF2, $encode($set($serialize($htmlF2), 'scopeAndContent', "Texto editado {$run}")));
            $check('control: un Fondo con título y sin signatura se sigue pudiendo editar', 302, $r['code']);
        }

        echo "== Sin nivel ==\n";
        $n0 = $count();
        $r = $add($set($set($form, 'levelOfDescription', $sinNivel), 'identifier', ''));
        $check('sin nivel y sin identificador: no se guarda', [200, null, $n0], [$r['code'], $r['location'], $count()]);
        $check('el mensaje pide el identificador', true, false !== strpos($r['body'], 'Escribe el identificador de la descripción.'));
    } finally {
        $newIds = QubitPdo::fetchAll('SELECT id FROM information_object WHERE id > ? ORDER BY id DESC', [$maxIdBefore], ['fetchMode' => PDO::FETCH_COLUMN]);
        foreach (array_unique(array_merge(array_reverse($created), array_map('intval', $newIds))) as $createdId) {
            if (null !== $o = QubitInformationObject::getById($createdId)) {
                $o->delete();
            }
        }
        $left = (int) $one('SELECT COUNT(*) FROM information_object WHERE identifier LIKE ?', ["{$run}%"])
            + (int) $one("SELECT COUNT(*) FROM information_object_i18n WHERE culture = 'es' AND title LIKE ?", ["%{$run}%"])
            + (int) $one('SELECT COUNT(*) FROM information_object WHERE id > ?', [$maxIdBefore]);
        echo 0 === $left ? "limpieza: datos de prueba eliminados\n" : "WARN  limpieza: quedan {$left} filas de prueba\n";
    }

    echo 0 === $fails ? "RESULTADO: OK\n" : "RESULTADO: {$fails} FAIL\n";

    exit(0 === $fails ? 0 : 1);
})();
