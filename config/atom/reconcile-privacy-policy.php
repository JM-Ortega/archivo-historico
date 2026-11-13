<?php

/*
 * RECONCILE de la página estática de política de privacidad (slug `privacy`, culturas es y en).
 * Fuente de verdad: el fichero versionado config/atom/content/privacy-policy.es.md. Escribe solo si difiere.
 * Se ejecuta con `php symfony tools:run` desde config/atom/reconcile.sh.
 *
 * Exit: 0 éxito; 89 fichero ilegible o vacío; 90 la página estática no existe; 91 la escritura falló;
 *       92 postcondición no satisfecha.
 */
(static function (): void {
    $slug = 'privacy';
    $cultures = ['es', 'en'];
    $title = 'Política de privacidad';
    $file = getenv('RECONCILE_PRIVACY_FILE') ?: '/project/content/privacy-policy.es.md';

    $log = static function (string $message): void {
        echo "reconcile: privacy-policy: {$message}\n";
    };
    $fail = static function (int $code, string $message) use ($log): never {
        $log($message);

        exit($code);
    };

    $desired = is_readable($file) ? rtrim(str_replace("\r\n", "\n", (string) file_get_contents($file))) : '';
    if ('' === $desired) {
        $fail(89, "no se puede leer o está vacío: {$file}");
    }

    $page = QubitStaticPage::getBySlug($slug);
    if (null === $page) {
        $fail(90, "no existe la página estática '{$slug}' (¿otro slug?)");
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
        $fail(91, 'la escritura falló ('.get_class($e).')');
    }

    $after = QubitStaticPage::getBySlug($slug);
    foreach ($cultures as $c) {
        if (null === $after || $read($after, $c) !== $desired) {
            $fail(92, "POSTCONDICIÓN NO SATISFECHA: el contenido releído ({$c}) no coincide");
        }
    }
    $log('escrita la versión del repo en '.implode(', ', $pending));
})();