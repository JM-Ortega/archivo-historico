<?php

/*
 * Sanitización de los parámetros de búsqueda (KAN-98 búsqueda básica, KAN-99 búsqueda avanzada).
 *
 * Contrato: recibe los parámetros GET tal como llegan y devuelve una copia normalizada. No lanza excepciones ni
 * rechaza la petición: lo que no cumple la regla de su campo se limpia o se descarta, y AtoM recibe un valor válido
 * o ninguno. Es lógica pura (sin Symfony ni base de datos) para poder probarla de forma aislada.
 *
 * No sustituye al escapado de salida (cada template escapa lo que muestra) ni a la validación de AtoM: reduce lo que
 * llega a Elasticsearch y evita errores por entradas mal formadas (p. ej. `query[]=x`, que AtoM resuelve con un 500).
 */
class arUnicaucaSearchInput
{
    // Longitud máxima, en caracteres, de un término de búsqueda (caja básica y criterios avanzados).
    public const MAX_TERM_LENGTH = 200;

    // Máximo de criterios avanzados (sq0..sqN) que se conservan.
    public const MAX_CRITERIA = 20;

    private const OPERATORS = ['and', 'or', 'not'];
    private const RANGE_TYPES = ['inclusive', 'exact'];

    // Fecha de los filtros: año (YYYY), año-mes (YYYY-MM) o fecha completa (YYYY-MM-DD).
    private const DATE_PATTERN = '/^\d{4}(-\d{2}(-\d{2})?)?$/';

    // Nombre de campo de ES que elige el selector sfN (p. ej. title, scopeAndContent, creators.i18n.en.authorizedFormOfName).
    private const FIELD_PATTERN = '/^[A-Za-z0-9_.]{1,100}$/';

    // Parámetros booleanos que AtoM interpreta con FILTER_VALIDATE_BOOLEAN.
    private const BOOLEAN_KEYS = ['topLod', 'onlyMedia', 'showAdvanced'];

    public static function sanitize(array $params): array
    {
        foreach ($params as $key => $value) {
            $key = (string) $key;
            $m = [];

            if ('query' === $key || 1 === preg_match('/^sq(\d+)$/', $key, $m)) {
                if (isset($m[1]) && (int) $m[1] >= self::MAX_CRITERIA) {
                    unset($params[$key]);

                    continue;
                }

                $params[$key] = self::term($value);
            } elseif (1 === preg_match('/^so(\d+)$/', $key)) {
                $params[$key] = self::choice($value, self::OPERATORS);
            } elseif (1 === preg_match('/^sf(\d+)$/', $key)) {
                $params[$key] = self::match($value, self::FIELD_PATTERN);
            } elseif ('startDate' === $key || 'endDate' === $key) {
                $params[$key] = self::match($value, self::DATE_PATTERN);
            } elseif ('rangeType' === $key) {
                $params[$key] = self::choice($value, self::RANGE_TYPES);
            } elseif (in_array($key, self::BOOLEAN_KEYS, true)) {
                $params[$key] = self::match($value, '/^(0|1|true|false)$/i');
            }

            if (null === $params[$key]) {
                unset($params[$key]);
            }
        }

        return $params;
    }

    // Texto libre: solo escalares, sin caracteres de control ni UTF-8 inválido, espacios colapsados y longitud acotada.
    public static function term($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        // Descarta bytes que no son UTF-8 válido (en lugar de dejar que lleguen rotos al índice).
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // Controles C0/C1 y separadores de línea/párrafo Unicode se vuelven espacio.
        $value = preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if (mb_strlen($value) > self::MAX_TERM_LENGTH) {
            $value = rtrim(mb_substr($value, 0, self::MAX_TERM_LENGTH));
        }

        return $value;
    }

    private static function choice($value, array $allowed): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : null;
    }

    private static function match($value, string $pattern): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return 1 === preg_match($pattern, $value) ? $value : null;
    }
}
