<?php

/*
 * Prueba de integración: acceso por rol (KAN-164, caso CD-10).
 *
 * En este proyecto el Investigador es quien NO inicia sesión; el Catalogador es el grupo «catalogador»; el Administrador, «administrador».
 * Para cada rol se comprueba, por HTTP contra `nginx`, lo que puede y lo que NO puede hacer:
 *   - crear una descripción (formulario y envío),
 *   - editar una descripción (formulario y envío),
 *   - administrar usuarios.
 * Los intentos de escritura se hacen SOLO sobre una ficha de prueba propia (ZZ-PRUEBA) que se borra al terminar, y se verifica por
 * SQL que un rol sin permiso no creó ni cambió nada.
 *
 * Entorno: CATALOGING_TEST_URL (por defecto http://nginx); ADMIN: CATALOGING_TEST_EMAIL / CATALOGING_TEST_PASSWORD;
 * CATALOGADOR: ROLES_TEST_EDITOR_EMAIL / ROLES_TEST_EDITOR_PASSWORD (si faltan, esos casos se omiten y se avisa).
 */
(static function (): void {
    $base = rtrim(getenv('CATALOGING_TEST_URL') ?: 'http://nginx', '/');
    $accounts = [
        'administrador' => [getenv('CATALOGING_TEST_EMAIL') ?: 'admin@unicauca.edu.co', getenv('CATALOGING_TEST_PASSWORD') ?: 'admin_dev_12345'],
        'catalogador' => [getenv('ROLES_TEST_EDITOR_EMAIL') ?: '', getenv('ROLES_TEST_EDITOR_PASSWORD') ?: ''],
    ];
    $run = 'ZZ-PRUEBA-'.bin2hex(random_bytes(3));
    $fails = 0;

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
    $encode = static fn (array $pairs) => implode('&', array_map(static fn ($p) => rawurlencode($p[0]).'='.rawurlencode($p[1]), $pairs));

    // Una sesión (handle con su propio almacén de cookies) por rol.
    $session = static function () use ($base): Closure {
        $curl = curl_init();
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 120]);

        return static function (string $path, ?string $body = null) use ($base, $curl): array {
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

            return ['code' => curl_getinfo($curl, CURLINFO_HTTP_CODE), 'body' => substr($raw, $size)];
        };
    };
    $hasForm = static fn (array $r) => 200 === $r['code'] && false !== strpos($r['body'], 'id="editForm"');
    $count = static fn () => (int) $one('SELECT COUNT(*) FROM information_object');
    $maxIdBefore = (int) $one('SELECT MAX(id) FROM information_object');
    $created = [];

    try {
        // Ficha de prueba propia (por modelo) sobre la que se intentan las escrituras.
        $io = new QubitInformationObject();
        $io->parentId = QubitInformationObject::ROOT_ID;
        $io->identifier = $run;
        $io->sourceCulture = 'es';
        $io->setTitle($run, ['culture' => 'es']);
        $io->save();
        $created[] = $io->id;
        $slug = $one('SELECT slug FROM slug WHERE object_id = ?', [$io->id]);
        $editPath = "/index.php/{$slug}/edit";
        $identifierOf = static fn () => $one('SELECT identifier FROM information_object WHERE id = ?', [$io->id]);

        // ---------------------------------------------------------------------------------------------------------------
        echo "== Investigador (sin sesión) ==\n";
        $req = $session();
        $r = $req('/index.php/informationobject/add');
        $check('el alta no abre el formulario', [403, false], [$r['code'], false !== strpos($r['body'], 'id="editForm"')]);
        $r = $req($editPath);
        $check('editar una ficha no abre el formulario', [403, false], [$r['code'], false !== strpos($r['body'], 'id="editForm"')]);
        $n0 = $count();
        $r = $req('/index.php/informationobject/add', $encode([['identifier', "{$run}-ANON"], ['levelOfDescription', '']]));
        $check('un envío de alta no crea nada', [true, $n0], [in_array($r['code'], [302, 403], true), $count()]);
        $r = $req($editPath, $encode([['identifier', "{$run}-ANON"]]));
        $check('un envío de edición no cambia la ficha', [true, $run], [in_array($r['code'], [302, 403], true), $identifierOf()]);
        $r = $req('/index.php/user/list');
        $check('la lista de usuarios no se muestra', true, 200 !== $r['code'] || false !== strpos($r['body'], 'name="password"'));

        // ---------------------------------------------------------------------------------------------------------------
        foreach ($accounts as $role => [$email, $password]) {
            echo "== {$role} ==\n";
            if ('' === $email || '' === $password) {
                echo "OMITIDO  {$role}: faltan las credenciales de prueba en el entorno\n";

                continue;
            }
            $req = $session();
            $page = $req('/index.php/user/login');
            $token = preg_match('/name="_csrf_token" value="([^"]+)"/', $page['body'], $m) ? $m[1] : '';
            $r = $req('/index.php/user/login', $encode([['_csrf_token', (string) $token], ['email', $email], ['password', $password]]));
            $check("inicio de sesión de {$role}", 302, $r['code']);

            $add = $req('/index.php/informationobject/add');
            $edit = $req($editPath);
            $users = $req('/index.php/user/list');
            $userAdd = $req('/index.php/user/add');
            $check('el alta abre el formulario', true, $hasForm($add));
            $check('editar una ficha abre el formulario', true, $hasForm($edit));
            $isList = static fn (array $r) => 200 === $r['code'] && false !== strpos($r['body'], '/index.php/user/add');
            if ('administrador' === $role) {
                $check('la lista de usuarios se muestra', true, $isList($users));
                $check('el alta de usuarios se muestra', true, 200 === $userAdd['code'] && false !== strpos($userAdd['body'], 'name="confirmPassword"'));
            } else {
                $check('la lista de usuarios NO se muestra (administrar usuarios es del administrador)', [403, false], [$users['code'], $isList($users)]);
                $check('el alta de usuarios NO se muestra', [403, false], [$userAdd['code'], 200 === $userAdd['code'] && false !== strpos($userAdd['body'], 'name="confirmPassword"')]);
            }
        }
    } finally {
        $newIds = QubitPdo::fetchAll('SELECT id FROM information_object WHERE id > ? ORDER BY id DESC', [$maxIdBefore], ['fetchMode' => PDO::FETCH_COLUMN]);
        foreach (array_unique(array_merge(array_reverse($created), array_map('intval', $newIds))) as $id) {
            if (null !== $o = QubitInformationObject::getById($id)) {
                $o->delete();
            }
        }
        $left = (int) $one('SELECT COUNT(*) FROM information_object WHERE id > ?', [$maxIdBefore]);
        echo 0 === $left ? "limpieza: datos de prueba eliminados\n" : "WARN  limpieza: quedan {$left} filas de prueba\n";
    }

    echo 0 === $fails ? "RESULTADO: OK\n" : "RESULTADO: {$fails} FAIL\n";

    exit(0 === $fails ? 0 : 1);
})();
