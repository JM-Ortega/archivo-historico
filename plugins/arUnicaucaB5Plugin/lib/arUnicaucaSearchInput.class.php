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
    // Longitud máxima, en caracteres, de la caja básica (`query`). KAN-140: 250, como pide el planning del Sprint 2.
    // Debe coincidir con js/search-limit.js (hay una prueba que lo comprueba).
    public const MAX_QUERY_LENGTH = 250;

    // Longitud máxima de cada criterio de la búsqueda avanzada (sqN). Valor provisional: el máximo por campo está por acordar.
    public const MAX_TERM_LENGTH = 200;

    // Máximo de criterios avanzados (sq0..sqN) que se conservan.
    public const MAX_CRITERIA = 20;

    private const OPERATORS = ['and', 'or', 'not'];
    private const RANGE_TYPES = ['inclusive', 'exact'];

    // Fecha de los filtros: año (YYYY), año-mes (YYYY-MM) o fecha completa (YYYY-MM-DD).
    private const DATE_PATTERN = '/^\d{4}(-\d{2}(-\d{2})?)?$/';

    // Nombre de campo de ES que elige el selector sfN (p. ej. title, scopeAndContent, creators.i18n.en.authorizedFormOfName).
    private const FIELD_PATTERN = '/^[A-Za-z0-9_.]{1,100}$/';

    // Forma de una signatura del Archivo: el sufijo entre paréntesis que abre con Ind., Col. o Rep. («(Ind. C II -24 mn)»).
    // El punto es obligatorio: sin él, «(Colombia)», «(Colonial)» o «(República)» se tomarían por una signatura.
    private const SIGNATURE_PATTERN = '/\(\s*(?:Ind|Col|Rep)\./iu';

    // KAN-163: caracteres de la sintaxis de consulta de Elasticsearch (`query_string`) que no se conservan, porque sueltos o mal
    // puestos hacen fallar la búsqueda («Search error encountered»): `/` (abre una expresión regular), `\`, `^`, `:`, `!`,
    // corchetes, llaves, `~` (difusa/proximidad) y `&`/`|` (operadores `&&` y `||`: la pantalla ya ofrece Y/O/No).
    private const SYNTAX_PATTERN = '/[\\\\\/\^:!\[\]\{\}~&|]/u';

    // Parámetros booleanos que AtoM interpreta con FILTER_VALIDATE_BOOLEAN.
    private const BOOLEAN_KEYS = ['topLod', 'onlyMedia', 'showAdvanced'];

    // La reescritura de sintaxis (signaturas como frase, neutralizeSyntax) solo aplica en la búsqueda. Los autocompletes y el
    // árbol también reciben `query` y deben conservar lo que se escribe: allí solo se limpian controles y longitud.
    public static function isSearchRoute(string $path): bool
    {
        return 1 === preg_match('#/informationobject/browse/?$#', $path);
    }

    public static function sanitize(array $params, bool $searchRoute = true): array
    {
        foreach ($params as $key => $value) {
            $key = (string) $key;
            $m = [];

            if ('query' === $key || 1 === preg_match('/^sq(\d+)$/', $key, $m)) {
                if (isset($m[1]) && (int) $m[1] >= self::MAX_CRITERIA) {
                    unset($params[$key]);

                    continue;
                }

                $params[$key] = self::term($value, 'query' === $key ? self::MAX_QUERY_LENGTH : self::MAX_TERM_LENGTH);

                // KAN-163: una signatura pegada tal cual («9052 (Ind. J II -3 cr)») se busca como frase literal.
                if ($searchRoute && is_string($params[$key])) {
                    $field = isset($m[1], $params['sf'.$m[1]]) && is_string($params['sf'.$m[1]]) ? trim($params['sf'.$m[1]]) : '';
                    $params[$key] = self::literalSignature($params[$key], 'identifier' === $field);
                    $params[$key] = self::neutralizeSyntax($params[$key]);
                }
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
    public static function term($value, int $max = self::MAX_TERM_LENGTH): ?string
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

        if (mb_strlen($value) > $max) {
            $value = rtrim(mb_substr($value, 0, $max));
        }

        return $value;
    }

    /**
     * Signaturas del Archivo: «5640 (Ind. C II -24 mn)». En la sintaxis de búsqueda de Elasticsearch el « -24» significa
     * «excluir 24» y los paréntesis agrupan, así que la signatura escrita tal cual no encuentra nada (el 99 % del
     * catálogo tiene « -»). Se envuelve entre comillas para buscarla como frase: siempre en el campo «Signatura»
     * (`$force`) y, en cualquier otro campo o en la búsqueda básica, solo si el texto tiene forma de signatura. El resto
     * del texto conserva la sintaxis del usuario (comillas, AND, comodines...). No se toca un texto que ya viene entre
     * comillas ni uno con comodín (`*` o `?`): quien lo escribe busca por prefijo.
     */
    public static function literalSignature(string $value, bool $force = false): string
    {
        $length = mb_strlen($value);
        if ('' === $value || ($length > 1 && '"' === mb_substr($value, 0, 1) && '"' === mb_substr($value, -1))) {
            return $value;
        }

        if (!$force && 1 !== preg_match(self::SIGNATURE_PATTERN, $value)) {
            return $value;
        }

        // Entre comillas el comodín deja de serlo: quien escribe «705*» busca por prefijo y se respeta.
        if (false !== strpbrk($value, '*?')) {
            return $value;
        }

        // Dentro de la frase no puede haber comillas ni barras invertidas (rompen la sintaxis); una signatura no las usa.
        $inside = trim(str_replace(['"', '\\'], '', $value));

        return '' === $inside ? '' : '"'.$inside.'"';
    }

    /**
     * KAN-163: evita el «Search error encountered» cuando el texto trae sintaxis de consulta mal formada.
     *
     * El texto llega a Elasticsearch como `query_string`, que interpreta símbolos y operadores: una `/` suelta abre una
     * expresión regular sin cerrar, y lo mismo pasa con una comilla o un paréntesis sin pareja, `:`, `^`, `!`, `~`, `&&`,
     * `||`, un `+` o `-` solo, o un AND/OR/NOT al borde o repetido. El analizador del índice ya trata esos símbolos como
     * separadores de palabras, así que cambiarlos por un espacio no altera lo que se encuentra y deja legible lo que la caja
     * vuelve a mostrar (no se escapa con `\`: se vería en la caja).
     *
     * Se conserva solo la sintaxis bien formada que el Archivo puede usar: palabras, frases entre comillas completas (también
     * la signatura de literalSignature()), comodines `*` y `?`, paréntesis con pareja, `-palabra` / `+palabra` y los
     * operadores AND, OR y NOT entre dos términos. Lo demás se vuelve espacio (lista blanca: es la opción segura).
     */
    public static function neutralizeSyntax(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        // Una comilla sin pareja no se puede interpretar: se quitan todas (las frases completas no cambian).
        if (1 === substr_count($value, '"') % 2) {
            $value = str_replace('"', ' ', $value);
        }

        $parts = preg_split('/("[^"]*")/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];

        // Paréntesis: solo se conservan si, fuera de comillas, abren y cierran en orden.
        $outside = '';
        foreach ($parts as $i => $part) {
            // Una frase entre comillas cuenta como un término (Q): si no, «("a" OR "b")» se vería como «( OR )».
            $outside .= 0 === $i % 2 ? $part : 'Q';
        }
        $depth = 0;
        $balanced = true;
        foreach (str_split(preg_replace('/[^()]/u', '', $outside) ?? '') as $c) {
            $depth += '(' === $c ? 1 : -1;
            if ($depth < 0) {
                $balanced = false;

                break;
            }
        }
        // Tampoco sirven un grupo vacío «()» ni un operador pegado al paréntesis «(AND a)» / «(a AND)».
        $balanced = $balanced && 0 === $depth
            && 1 !== preg_match('/\(\s*(?:\)|(?:AND|OR|NOT)\b)|\b(?:AND|OR|NOT)\s*\)/u', $outside);

        $last = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if (0 === $i % 2) {
                $part = preg_replace(self::SYNTAX_PATTERN, ' ', $part) ?? '';
                // «-OR», «+AND»: un prefijo pegado a un operador no es ni lo uno ni lo otro.
                $part = preg_replace('/[+-](?=(?:AND|OR|NOT)\b)/u', ' ', $part) ?? '';
                // `+` y `-` solo como prefijo de una palabra, de una frase o de un grupo («-a», «+"a b"») o dentro de ella
                // («a-b»). Si una frase sigue a este tramo, se simula su comilla para no quitar el prefijo.
                $probe = $part.($i < $last ? '"' : '');
                $probe = preg_replace('/(?<![\p{L}\p{N}])[+-](?![\p{L}\p{N}"(])/u', ' ', $probe) ?? '';
                $part = $i < $last ? substr($probe, 0, -1) : $probe;
                $parts[$i] = $balanced ? $part : str_replace(['(', ')'], ' ', $part);
            } else {
                // Dentro de comillas todo es literal, salvo la barra invertida (escapa la comilla de cierre).
                $parts[$i] = str_replace('\\', ' ', $part);
            }
        }

        // Cada frase entre comillas va separada de lo que tiene al lado («AND"a b"» no es un operador), salvo el prefijo
        // «-"a b"» / «+"a b"» y el paréntesis de apertura. Se arma por posición (las frases son las partes impares): un
        // regex sobre el texto unido emparejaba la comilla de cierre de una frase con la de apertura de la siguiente.
        $joined = '';
        foreach ($parts as $i => $part) {
            if (1 === $i % 2) {
                $joined .= ('' === $joined || 1 === preg_match('/[\s+\-(]$/u', $joined) ? '' : ' ').$part.' ';
            } else {
                $joined .= $part;
            }
        }
        $value = trim(preg_replace('/\s+/u', ' ', $joined) ?? '');

        // Operadores AND, OR y NOT: solo entre dos términos. AND y OR al principio o al final se descartan, y NOT al final
        // también; un NOT al principio se conserva (niega). Si hay varios seguidos se conserva el último, salvo «AND NOT» y
        // «OR NOT», que son válidos.
        preg_match_all('/"[^"]*"|\S+/u', $value, $m);
        $out = [];
        $pending = [];
        foreach ($m[0] as $token) {
            if (in_array($token, ['AND', 'OR', 'NOT'], true)) {
                $pending[] = $token;

                continue;
            }
            if ([] !== $out && [] !== $pending) {
                $n = count($pending);
                $out[] = 2 === $n && 'NOT' === $pending[1] && 'NOT' !== $pending[0] ? implode(' ', $pending) : $pending[$n - 1];
                // «NOT -a» es una doble negación que Elasticsearch rechaza: el operador ya niega, el prefijo sobra.
                if ('NOT' === $pending[$n - 1]) {
                    $token = ltrim($token, '+-');
                }
            } elseif ([] === $out && [] !== $pending && 'NOT' === end($pending)) {
                // «NOT oficio» al principio niega (Elasticsearch devuelve todo menos «oficio»): descartarlo daba lo contrario.
                $out[] = 'NOT';
                $token = ltrim($token, '+-');
            }
            $pending = [];
            $out[] = $token;
        }

        // Sin espacios pegados a los paréntesis «( a )» → «(a)», pero solo fuera de las frases: dentro son literales.
        $bits = preg_split('/("[^"]*")/u', implode(' ', $out), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($bits as $i => $bit) {
            if (0 === $i % 2) {
                $bits[$i] = preg_replace(['/\(\s+/u', '/\s+\)/u'], ['(', ')'], $bit) ?? '';
            }
        }

        return trim(implode('', $bits));
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
