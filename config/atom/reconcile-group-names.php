<?php

/*
 * RECONCILE del nombre visible del grupo «editor» de AtoM (id 101, QubitAclGroup::EDITOR_ID): en la cultura `es` se llama
 * «catalogador», que es el rol del Archivo al que corresponde.
 *
 * Se ejecuta con `php symfony tools:run` (contexto Symfony/Propel de AtoM ya inicializado) desde config/atom/reconcile.sh,
 * dentro del servicio `reconcile`. Toca SOLO el nombre `es` del grupo 101: ni sus permisos, ni sus miembros, ni el nombre `en`
 * (AtoM y el código identifican el grupo por id, nunca por nombre), ni ningún otro grupo.
 *
 * Pasos: observar (SQL) → validar → escribir con el mecanismo de AtoM (QubitAclGroup) solo si difiere → exigir la postcondición
 * releyendo la BD. Los nombres de grupo no se cachean en Memcached ni se indexan en Elasticsearch: no hay nada que invalidar.
 *
 * Estados aceptados antes de escribir: nombre `es` ausente o igual a «editor» (default de AtoM; sin distinguir mayúsculas).
 * Cualquier otro nombre es estado alterado por alguien y es STOP sin escribir (no se autocorrige). También es STOP que otro grupo
 * ya se llame «catalogador» en `es` (sin distinguir mayúsculas ni acentos): no se duplica.
 *
 * Idempotente: si ya se llama «catalogador» no se escribe.
 *
 * Exit: 0 éxito; 97 no se pudo observar o el grupo 101 no existe; 98 la escritura falló; 99 postcondición no satisfecha;
 *       100 no reconciliable (nombre alterado u homónimo en otro grupo), detectado ANTES de escribir.
 */
(static function (): void {
    $groupId = QubitAclGroup::EDITOR_ID;
    $culture = 'es';
    $desired = 'catalogador';
    $upstreamDefault = 'editor';

    $log = static function (string $message): void {
        echo "reconcile: nombre del grupo editor (101): {$message}\n";
    };
    $fail = static function (int $code, string $message) use ($log): never {
        $log($message);

        exit($code);
    };
    // Comparación sin mayúsculas ni acentos, como el chequeo de homónimos de reconcile-terms.php.
    $fold = static function (?string $s): string {
        $s = mb_strtolower(trim((string) $s), 'UTF-8');

        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    };

    $observe = static function () use ($groupId, $culture, $desired, $fold): array {
        $exists = 1 === count(QubitPdo::fetchAll('SELECT id FROM acl_group WHERE id = ?', [$groupId]));
        $name = null;
        foreach (QubitPdo::fetchAll('SELECT name FROM acl_group_i18n WHERE id = ? AND culture = ?', [$groupId, $culture]) as $r) {
            $name = $r->name;
        }
        $homonyms = [];
        foreach (QubitPdo::fetchAll('SELECT id, name FROM acl_group_i18n WHERE id <> ? AND culture = ?', [$groupId, $culture]) as $r) {
            if ($fold($r->name) === $fold($desired)) {
                $homonyms[] = (int) $r->id;
            }
        }

        return ['exists' => $exists, 'name' => $name, 'homonyms' => $homonyms];
    };

    try {
        $before = $observe();
    } catch (Throwable $e) {
        $fail(97, 'no se pudo observar el estado ('.get_class($e).')');
    }
    if (!$before['exists']) {
        $fail(97, 'el grupo 101 no existe; no se toca nada');
    }
    if ([] !== $before['homonyms']) {
        $fail(100, "NO RECONCILIABLE: otro grupo ya se llama «{$desired}» (id ".implode(',', $before['homonyms']).'); no se escribe');
    }

    if ($desired === $before['name']) {
        $log("ya se llama «{$desired}»; sin escritura");
    } elseif (null === $before['name'] || '' === trim($before['name']) || $fold($upstreamDefault) === $fold($before['name'])) {
        try {
            $group = QubitAclGroup::getById($groupId);
            $group->setName($desired, ['culture' => $culture]);
            $group->save();
        } catch (Throwable $e) {
            $fail(98, 'la escritura falló ('.get_class($e).')');
        }
        $log('renombrado '.var_export($before['name'], true)." → «{$desired}»");
    } else {
        $fail(100, 'NO RECONCILIABLE: el nombre es '.var_export($before['name'], true)." (se esperaba «{$upstreamDefault}» o «{$desired}»); no se escribe");
    }

    try {
        $after = $observe();
    } catch (Throwable $e) {
        $fail(99, 'POSTCONDICIÓN NO SATISFECHA: no se pudo releer el estado ('.get_class($e).')');
    }
    if ($desired !== $after['name']) {
        $fail(99, 'POSTCONDICIÓN NO SATISFECHA: el nombre en la BD es '.var_export($after['name'], true));
    }
    if ([] !== $after['homonyms']) {
        $fail(99, 'POSTCONDICIÓN NO SATISFECHA: apareció un homónimo (id '.implode(',', $after['homonyms']).')');
    }
    $log("OK: nombre {$culture} = «{$desired}»");
})();
