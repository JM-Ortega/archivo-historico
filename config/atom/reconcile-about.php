<?php

/*
 * RECONCILE de la página estática «Acerca de la plataforma» (slug `about`, culturas es y en).
 * Fuente de verdad: el fichero versionado config/atom/content/about-platform.es.md (Markdown). Escribe solo si difiere.
 * El diseño (cabecera, panel) lo pone el theme: plugins/arUnicaucaB5Plugin/modules/staticpage/templates/_aboutPlatform.php.
 * Se ejecuta con `php symfony tools:run` desde config/atom/reconcile.sh.
 *
 * Exit: 0 éxito; 93 fichero ilegible o vacío; 94 la página estática no existe; 95 la escritura falló;
 *       96 postcondición no satisfecha.
 */
(static function (): void {
    $slug = 'about';
    $cultures = ['es', 'en'];
    $title = 'Acerca de la plataforma';
    $file = getenv('RECONCILE_ABOUT_FILE') ?: '/project/content/about-platform.es.md';

    $log = static function (string $message): void {
        echo "reconcile: about-platform: {$message}\n";
    };
    $fail = static function (int $code, string $message) use ($log): never {
        $log($message);

        exit($code);
    };

    $desired = is_readable($file) ? rtrim(str_replace("\r\n", "\n", (string) file_get_contents($file))) : '';
    if ('' === $desired) {
        $fail(93, "no se puede leer o está vacío: {$file}");
    }

    $page = QubitStaticPage::getBySlug($slug);
    if (null === $page) {
        $fail(94, "no existe la página estática '{$slug}' (¿otro slug?)");
    }

    $read = static fn ($p, string $c): string => rtrim(str_replace("\r\n", "\n", (string) $p->getContent(['culture' => $c])));

    $pending = array_values(array_filter($cultures, static fn (string $c): bool => $read($page, $c) !== $desired));
    if ([] === $pending) {
        $log('ya coincide en '.implode(', ', $cultures).'; sin escritura');

        return;
    }

    try {
        foreach ($pending as $c) {
            $page->setContent($desired, ['culture' => $c]);
            $page->setTitle($title, ['culture' => $c]);
        }
        $page->save();
    } catch (Throwable $e) {
        $fail(95, 'la escritura falló ('.get_class($e).')');
    }

    $after = QubitStaticPage::getBySlug($slug);
    foreach ($cultures as $c) {
        if (null === $after || $read($after, $c) !== $desired) {
            $fail(96, "POSTCONDICIÓN NO SATISFECHA: el contenido releído ({$c}) no coincide");
        }
    }

    // La portada de la página usa una copia en caché del contenido (staticpage:<id>:<cultura>); la edición desde la interfaz
    // la invalida y aquí también, para no seguir mostrando el texto anterior. No es fatal si la caché no responde.
    try {
        if (null !== $cache = QubitCache::getInstance()) {
            foreach (array_unique(array_merge($cultures, (array) sfConfig::get('app_i18n_languages', []))) as $c) {
                $cache->remove('staticpage:'.$after->id.':'.$c);
            }
        }
    } catch (Throwable $e) {
        $log('aviso: no se pudo invalidar la caché ('.get_class($e).'); si ves el texto anterior, reinicia memcached');
    }

    $log('escrita la versión del repo en '.implode(', ', $pending));
})();
