<?php

/*
 * RECONCILE del ajuste global «Múltiples instituciones» (`multi_repository`) de AtoM: debe estar en 0 (una sola institución).
 * Con 0, AtoM oculta por sí solo el filtro y la faceta de institución en la búsqueda avanzada y en el listado de resultados,
 * el filtro de institución de «Actualizaciones de descripciones» y las opciones por institución de la ficha de usuario.
 * Se ejecuta con `php symfony tools:run` desde config/atom/reconcile.sh. Escribe solo si difiere.
 *
 * Exit: 0 éxito; 101 el ajuste no existe; 102 la escritura falló; 103 postcondición no satisfecha.
 */
(static function (): void {
    $name = 'multi_repository';
    $desired = '0';

    $log = static function (string $message): void {
        echo "reconcile: multi-repository: {$message}\n";
    };
    $fail = static function (int $code, string $message) use ($log): never {
        $log($message);

        exit($code);
    };

    $read = static function () use ($name): ?string {
        $s = QubitSetting::getByName($name);

        return null === $s ? null : (string) $s->getValue(['sourceCulture' => true]);
    };

    $current = $read();
    if (null === $current) {
        $fail(101, "no existe el ajuste '{$name}'");
    }
    if ($desired === $current) {
        $log("ya está en {$desired}; sin escritura");

        return;
    }

    try {
        $setting = QubitSetting::getByName($name);
        // sourceCulture: evita discrepancias entre culturas, igual que el formulario de ajustes globales de AtoM.
        $setting->setValue($desired, ['sourceCulture' => true]);
        $setting->save();
    } catch (Throwable $e) {
        $fail(102, 'la escritura falló ('.get_class($e).')');
    }

    if ($desired !== $read()) {
        $fail(103, 'POSTCONDICIÓN NO SATISFECHA: el valor releído no coincide');
    }

    // AtoM guarda los ajustes en caché (settings:i18n:<cultura>); la pantalla de ajustes la invalida al guardar y aquí también.
    try {
        if (null !== $cache = QubitCache::getInstance()) {
            $cache->removePattern('settings:i18n:*');
        }
    } catch (Throwable $e) {
        $log('aviso: no se pudo invalidar la caché ('.get_class($e).'); si ves el cambio tarde, reinicia memcached');
    }

    $log("{$name}: {$current} → {$desired}");
})();
