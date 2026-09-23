<?php

/*
 * RECONCILE de UNA sola propiedad PROJECT-MANAGED de la tabla `setting`: check_for_updates = 0.
 *
 * Se ejecuta con `php symfony tools:run` (contexto Symfony/Propel de AtoM ya inicializado) desde config/atom/reconcile.sh,
 * dentro del servicio `reconcile`. NO es un reconciliador genérico de settings: toca únicamente esta fila y nunca otra.
 *
 * Por qué: con el default upstream (1) un administrador dispara un POST síncrono a accesstomemory.org desde el layout.
 *
 * Pasos: observar (SQL) → validar (una sola fila; sin valores propios en otras culturas que anularían el 0) → escribir con el
 * mecanismo de AtoM (QubitSetting, sobre la cultura fuente, como hace la pantalla de settings globales) solo si difiere →
 * invalidar SIEMPRE la caché de settings (`settings:i18n:*`, con QubitCache, como las acciones administrativas de AtoM; nunca
 * flush_all, que destruiría las sesiones de Memcached) → exigir la postcondición releyendo la BD y la caché. `tools:settings set`
 * no invalida la caché: las requests seguirían viendo el valor viejo hasta ~24 h.
 *
 * Un valor propio en otra cultura que impediría satisfacer el desired state es STOP ANTES de cualquier efecto: ni escritura en la
 * BD (tampoco del valor fuente), ni modificación del override, ni invalidación de la caché. El proyecto no define política para
 * eliminar o sobrescribir ese override. La postcondición conserva la misma comprobación como defensa frente a cambios concurrentes.
 *
 * Idempotente: si la BD ya está en 0 no se escribe; la invalidación solo descarta una caché derivada que se repuebla sola.
 *
 * Exit: 0 éxito; 73 no se pudo observar; 74 la escritura falló; 75 no se pudo invalidar la caché;
 *       76 no reconciliable (override en otra cultura, detectado ANTES de escribir) o postcondición no satisfecha (BD o caché).
 */
(static function (): void {
    $name = 'check_for_updates';
    $desired = '0';

    $log = static function (string $message) use ($name): void {
        echo "reconcile: {$name}: {$message}\n";
    };
    $fail = static function (int $code, string $message) use ($log): never {
        $log($message);

        exit($code);
    };

    // Filas i18n del setting (sin scope) con su cultura fuente. Lectura directa: independiente del objeto Propel que se escriba.
    $observe = static function () use ($name): array {
        $rows = QubitPdo::fetchAll(
            'SELECT s.id, s.source_culture, i.culture, i.value FROM setting s LEFT JOIN setting_i18n i ON i.id = s.id '
            .'WHERE s.name = ? AND (s.scope IS NULL OR s.scope = "")',
            [$name]
        );
        $ids = array_unique(array_map(static fn ($r) => $r->id, $rows));
        $source = null;
        $overrides = [];
        foreach ($rows as $r) {
            if (null === $r->culture) {
                continue;
            }
            if ($r->culture === $r->source_culture) {
                $source = (string) $r->value;
            } elseif ('' !== (string) $r->value) {
                $overrides[$r->culture] = (string) $r->value;
            }
        }

        return ['settings' => count($ids), 'source' => $source, 'overrides' => $overrides];
    };

    try {
        $before = $observe();
    } catch (Throwable $e) {
        $fail(73, 'no se pudo observar el estado ('.get_class($e).')');
    }
    if ($before['settings'] > 1) {
        $fail(73, 'existe más de una fila con este nombre; no se toca nada');
    }
    // STOP antes de escribir: un valor propio en otra cultura anularía el 0 y el proyecto no decide qué hacer con él.
    if ([] !== $before['overrides']) {
        $fail(76, 'NO RECONCILIABLE: hay valores propios en otras culturas ('.implode(',', array_keys($before['overrides'])).') que anularían el 0; no se escribe, no se modifican y no se invalida la caché');
    }

    if (1 === $before['settings'] && $desired === $before['source']) {
        $log('ya es 0 en la BD; sin escritura');
    } else {
        try {
            $setting = QubitSetting::getByName($name);
            if (null === $setting) {
                // Como hace AtoM con otros settings creados bajo demanda; una instalación compatible ya la trae de fixtures.
                $setting = QubitSetting::createNewSetting($name, $desired);
                $log('no existía; creada con valor 0');
            }
            $setting->setValue($desired, ['sourceCulture' => true]);
            $setting->save();
        } catch (Throwable $e) {
            $fail(74, 'la escritura falló ('.get_class($e).')');
        }
        $log('escrito 0 en la BD');
    }

    try {
        QubitCache::getInstance()->removePattern('settings:i18n:*');
    } catch (Throwable $e) {
        $fail(75, 'no se pudo invalidar la caché de settings ('.get_class($e).')');
    }
    $log('caché de settings invalidada (settings:i18n:*)');

    // Postcondición 1: la BD.
    $after = $observe();
    if (1 !== $after['settings'] || $desired !== $after['source']) {
        $fail(76, 'POSTCONDICIÓN NO SATISFECHA: el valor en la BD no es 0');
    }
    if ([] !== $after['overrides']) {
        $fail(76, 'POSTCONDICIÓN NO SATISFECHA: hay valores propios en otras culturas ('.implode(',', array_keys($after['overrides'])).') que anularían el 0; no se tocan');
    }

    // Postcondición 2: lo que una request vería. Valor efectivo recalculado como lo hace QubitSettingsFilter…
    $effective = QubitSetting::getSettingsArray()['app_'.$name] ?? null;
    if ($desired !== (string) $effective) {
        $fail(76, 'POSTCONDICIÓN NO SATISFECHA: el valor efectivo recalculado no es 0');
    }
    // …y ninguna entrada de caché de settings que exista (p. ej. repoblada por un atom en marcha) puede seguir con otro valor.
    $cache = QubitCache::getInstance();
    $cultures = array_unique(array_merge(
        [sfConfig::get('sf_default_culture'), 'en'],
        QubitPdo::fetchAll('SELECT DISTINCT culture FROM setting_i18n', [], ['fetchMode' => PDO::FETCH_COLUMN])
    ));
    foreach ($cultures as $culture) {
        $key = 'settings:i18n:'.$culture;
        if ($cache->has($key)) {
            $cached = unserialize($cache->get($key));
            if ($desired !== (string) ($cached['app_'.$name] ?? '')) {
                $fail(76, "POSTCONDICIÓN NO SATISFECHA: la caché {$key} conserva otro valor");
            }
        }
    }
    $log('OK: BD = 0, valor efectivo = 0, caché de settings sin valores obsoletos');
})();
