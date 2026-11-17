<?php

/**
 * Formulario de catalogación devuelto por un error de validación: reconstrucción de lo que el catalogador envió.
 *
 * Cuando el POST no es válido, la action no guarda nada y vuelve a pintar el formulario. Los campos simples conservan su
 * valor (el sfForm está enlazado), pero AtoM pinta:
 *   - los selects (también los autocompletes) solo con las opciones que ya estaban guardadas, y
 *   - las tablas de filas repetibles (fechas, notas, identificadores alternativos, niveles inferiores) desde la BD,
 * así que lo nuevo o editado se perdería. Aquí se recupera desde el POST, sin cambiar qué ni cómo se guarda: las filas se
 * vuelven a enviar con los mismos nombres de campo, ids incluidos.
 */
class arUnicaucaCatalogingResubmit
{
    /** El formulario se está pintando tras un POST (si el POST hubiera sido válido, la action ya habría redirigido). */
    public static function active(): bool
    {
        return sfContext::getInstance()->getRequest()->isMethod('post');
    }

    /**
     * Filas enviadas de una tabla repetible ($name[i][campo]), en el orden enviado. Se descartan las filas sin `id` cuyos
     * $fields estén todos vacíos (la fila vacía final que siempre lleva la tabla).
     *
     * @return array[] cada fila con todos sus campos como string (los ausentes como '')
     */
    public static function rows(string $name, array $fields): array
    {
        $posted = sfContext::getInstance()->getRequest()->getPostParameter($name);
        if (!is_array($posted)) {
            return [];
        }

        $rows = [];
        foreach ($posted as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = [];
            foreach (array_unique(array_merge(['id'], $fields, array_keys($item))) as $field) {
                $row[$field] = isset($item[$field]) && is_string($item[$field]) ? $item[$field] : '';
            }
            $empty = '' === implode('', array_map(static fn ($f) => $row[$f], $fields));
            if ('' === $row['id'] && $empty) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Recurso de AtoM al que apunta una URL de recurso (como las que envían los selects y autocompletes), o null. */
    public static function resource(string $url): ?QubitObject
    {
        if ('' === $url) {
            return null;
        }
        $params = sfContext::getInstance()->getRouting()->parse(Qubit::pathInfo($url));
        $resource = is_array($params) && isset($params['_sf_route']->resource) ? $params['_sf_route']->resource : null;

        return $resource instanceof QubitObject ? $resource : null;
    }

    /**
     * Añade a cada select del formulario enlazado las opciones enviadas que no estaban entre sus opciones (p. ej. un
     * Remitente elegido o creado en el autocomplete y aún no guardado), para que vuelvan seleccionadas. Solo valores que
     * resuelven a un recurso existente: el valor que causó el error no se resucita.
     */
    public static function restoreChoices(sfForm $form): void
    {
        if (!$form->isBound()) {
            return;
        }

        foreach ($form->getWidgetSchema()->getFields() as $name => $widget) {
            if (!$widget instanceof sfWidgetFormSelect || !is_array($choices = $widget->getOption('choices'))) {
                continue;
            }

            $added = false;
            foreach ((array) $form[$name]->getValue() as $value) {
                if (!is_string($value) || self::hasChoice($choices, $value) || null === $resource = self::resource($value)) {
                    continue;
                }
                $choices[$value] = $resource;
                $added = true;
            }
            if ($added) {
                $widget->setOption('choices', $choices);
            }
        }
    }

    private static function hasChoice(array $choices, string $value): bool
    {
        foreach ($choices as $key => $label) {
            if (is_array($label) ? self::hasChoice($label, $value) : (string) $key === $value) {
                return true;
            }
        }

        return false;
    }
}
