<?php

/*
 * OCULTO-008 y OCULTO-014 · Bloqueo de las direcciones de «Registro de autoridad» e «Institución archivística».
 *
 * Responde 404 a las páginas de registros de autoridad (listado, ficha, edición, borrado, renombrado, exportación EAC y
 * «registros de autoridad relacionados» de un término) y a las de instituciones archivísticas (módulos repository y
 * sfIsdiahPlugin: listado, ficha, formulario, edición, borrado, colecciones, tema y límites) para TODOS los usuarios,
 * administradores incluidos: el archivo no maneja esa información en este prototipo.
 *
 * Lo que NO se bloquea, porque el formulario de catalogación lo necesita (nombres de Remitente y Destinatario):
 *  - actor/autocomplete: la lista de sugerencias del campo de nombre.
 *  - La creación de un registro de autoridad nuevo desde ese campo: AtoM abre sfIsaarPlugin/edit en un iframe oculto
 *    (js/autocomplete.js). Se permite solo para un registro nuevo y solo desde un iframe; abrir /actor/add a mano o desde un
 *    enlace sigue dando 404.
 *
 * Se conecta en arUnicaucaB5PluginConfiguration::initialize() al evento controller.change_action.
 */
class arUnicaucaAuthorityRecordsGuard
{
    /** Módulo => acciones bloqueadas ('*' = todas). */
    private const BLOCKED = [
        'actor' => ['browse', 'delete', 'rename', 'relatedInformationObjects'],
        'sfIsaarPlugin' => ['index', 'actorEvents'],
        'sfEacPlugin' => ['*'],
        'term' => ['relatedAuthorities'],
        // OCULTO-014: instituciones archivísticas.
        'repository' => ['*'],
        'sfIsdiahPlugin' => ['*'],
    ];

    public static function listen(sfEvent $event): void
    {
        $module = (string) $event['module'];
        $action = (string) $event['action'];

        if (self::isBlocked($module, $action, sfContext::getInstance()->getRequest())) {
            throw new sfError404Exception(sprintf('Sección bloqueada (%s/%s).', $module, $action));
        }
    }

    public static function isBlocked(string $module, string $action, $request): bool
    {
        // Formulario de registro de autoridad (/actor/add, /<slug>/edit): solo la creación rápida desde otro formulario.
        if ('sfIsaarPlugin' === $module && 'edit' === $action) {
            return !self::isQuickAdd($request);
        }

        // El campo «Institución archivística» sigue en el formulario de catalogación: su lista de sugerencias no se bloquea.
        if ('repository' === $module && 'autocomplete' === $action) {
            return false;
        }

        $actions = self::BLOCKED[$module] ?? [];

        return in_array('*', $actions, true) || in_array($action, $actions, true);
    }

    private static function isQuickAdd($request): bool
    {
        $route = $request->getAttribute('sf_route');
        if (is_object($route) && isset($route->resource)) {
            return false; // editar un registro que ya existe
        }

        // Sec-Fetch-Dest: «iframe» desde el widget; «document» si alguien abre la dirección o sigue un enlace.
        $dest = strtolower((string) $request->getHttpHeader('Sec-Fetch-Dest'));
        if ('' !== $dest) {
            return 'iframe' === $dest;
        }

        // Navegador antiguo o conexión sin TLS (no envía Sec-Fetch-Dest): solo si la petición viene de una página del propio sitio.
        $referer = parse_url((string) $request->getHttpHeader('referer'), PHP_URL_HOST);
        $host = explode(':', (string) $request->getHost())[0];

        return is_string($referer) && '' !== $referer && 0 === strcasecmp($referer, $host);
    }
}
