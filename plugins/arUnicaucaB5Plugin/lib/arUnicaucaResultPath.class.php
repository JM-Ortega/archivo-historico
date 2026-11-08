<?php

/**
 * Ruta jerárquica de un resultado de búsqueda (KAN-138): «Independencia / CIVIL / Minas».
 *
 * Alcance: arma el texto desde `ancestors` (ids que ya trae el índice de AtoM), sin la raíz del sistema (id 1). Cada título
 * se lee una sola vez por petición (los hermanos comparten ancestros). No consulta Elasticsearch ni modifica nada.
 *
 * Contrato: titles(array $ancestors, string $culture): lista de títulos (strings sin escapar, de la raíz hacia abajo);
 * vacía si no hay ancestros. Los ids que no existen o no tienen título se omiten.
 */
class arUnicaucaResultPath
{
    private static $cache = [];

    public static function titles($ancestors, $culture = 'es')
    {
        $titles = [];
        // AtoM entrega los arreglos del índice dentro de un decorador de salida: se recorre, no se castea.
        $ids = $ancestors instanceof Traversable ? iterator_to_array($ancestors, false) : (array) $ancestors;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= QubitInformationObject::ROOT_ID) {
                continue;
            }
            $key = $culture.':'.$id;
            if (!array_key_exists($key, self::$cache)) {
                $object = QubitInformationObject::getById($id);
                self::$cache[$key] = $object ? trim((string) $object->getTitle(['culture' => $culture, 'cultureFallback' => true])) : '';
            }
            if ('' !== self::$cache[$key]) {
                $titles[] = self::$cache[$key];
            }
        }

        return $titles;
    }
}
