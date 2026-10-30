<?php

/*
 * OCULTO-008 · Bloqueo de las direcciones de la sección «Registro de autoridad».
 *
 * Responde 404 a las páginas de registros de autoridad (listado, ficha, edición, borrado, renombrado, exportación EAC y
 * «registros de autoridad relacionados» de un término) para TODOS los usuarios, administradores incluidos: el archivo no
 * maneja esa información en este prototipo. Los menús y los enlaces se ocultan aparte (OCULTO-004 a 007).
 *
 * Lo que NO se bloquea, porque los formularios de catalogación lo necesitan (nombres de Remitente y Destinatario):
 *  - actor/autocomplete: la lista de sugerencias del campo de nombre.
 *  - La creación de un registro nuevo desde ese campo: AtoM abre sfIsaarPlugin/edit en un iframe oculto (js/autocomplete.js)
 *    con linkExisting=true. Se permite solo para un registro nuevo y, si el navegador envía Sec-Fetch-Dest, solo dentro de un
 *    iframe; así, abrir /actor/add a mano sigue dando 404.
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
    ];

    public static function listen(sfEvent $event): void
    {
        $module = (string) $event['module'];
        $action = (string) $event['action'];

        if (self::isBlocked($module, $action, sfContext::getInstance()->getRequest())) {
            throw new sfError404Exception(sprintf('Sección «Registro de autoridad» bloqueada (%s/%s).', $module, $action));
        }
    }

    public static function isBlocked(string $module, string $action, $request): bool
    {
        // Formulario de registro de autoridad (/actor/add, /<slug>/edit): solo la creación rápida desde otro formulario.
        if ('sfIsaarPlugin' === $module && 'edit' === $action) {
            return !self::isQuickAdd($request);
        }

        $actions = self::BLOCKED[$module] ?? [];

        return in_array('*', $actions, true) || in_array($action, $actions, true);
    }

    private static function isQuickAdd($request): bool
    {
        $route = $request->getAttribute('sf_route');
        $isNew = !(is_object($route) && isset($route->resource));

        $flag = $request->getParameter('linkExisting', $request->getPostParameter('linkExisting'));
        $linkExisting = filter_var($flag, FILTER_VALIDATE_BOOLEAN);

        // Sec-Fetch-Dest: «iframe» en el widget; «document» si alguien abre la dirección; vacío si el navegador no lo envía.
        $dest = strtolower((string) $request->getHttpHeader('Sec-Fetch-Dest'));

        return $isNew && $linkExisting && ('' === $dest || 'iframe' === $dest);
    }
}
