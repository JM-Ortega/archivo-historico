<?php

/**
 * Resalta en los resultados de búsqueda los términos que escribió la persona (KAN-138).
 *
 * Alcance: recibe HTML ya escapado por AtoM (título, extracto) y envuelve en <mark> las coincidencias que aparecen en texto,
 * nunca dentro de etiquetas ni de atributos. No consulta ni modifica el índice: solo decora lo que ya se iba a mostrar.
 * La consulta llega saneada por arUnicaucaSearchInput (longitud, comillas, operadores), pero aquí no se asume.
 *
 * Contrato:
 * - terms($query): lista de palabras (>= 2 caracteres, sin operadores AND/OR/NOT ni comodines, máx. 8, sin repetir).
 * - mark($html, $terms): $html con <mark class="ah-hit">…</mark>; sin términos devuelve $html igual.
 * - Coincide sin distinguir mayúsculas y con las vocales tildadas o sin tilde (Popayán = popayan) y la ñ como n (Quiñones = quinones),
 *   igual que el buscador (su analizador de español trata ñ y n como la misma letra; con «quinones» devuelve los mismos 42 resultados).
 */
class arUnicaucaResultHighlight
{
    public const MAX_TERMS = 8;
    public const MIN_LENGTH = 2;

    public static function terms($query)
    {
        $query = (string) $query;
        preg_match_all('/[\p{L}\p{N}]+/u', $query, $found);
        $terms = [];
        foreach ($found[0] as $word) {
            if (mb_strlen($word, 'UTF-8') < self::MIN_LENGTH || in_array(mb_strtoupper($word, 'UTF-8'), ['AND', 'OR', 'NOT'], true)) {
                continue;
            }
            $terms[mb_strtolower($word, 'UTF-8')] = $word;
            if (count($terms) >= self::MAX_TERMS) {
                break;
            }
        }

        return array_values($terms);
    }

    public static function mark($html, array $terms)
    {
        $html = (string) $html;
        if ([] === $terms || '' === $html) {
            return $html;
        }
        // Palabra completa; el '&' previo evita marcar dentro de entidades HTML (&amp;).
        $pattern = '/(?<![\p{L}\p{N}&])('.implode('|', array_map([self::class, 'pattern'], $terms)).')(?![\p{L}\p{N}])/iu';
        $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ('' === $part || '<' === $part[0]) {
                continue; // etiqueta: no se toca
            }
            $parts[$i] = preg_replace($pattern, '<mark class="ah-hit">$1</mark>', $part);
        }

        return implode('', $parts);
    }

    private static function pattern($term)
    {
        $map = ['n' => '[nñ]', 'a' => '[aáàä]', 'e' => '[eéèë]', 'i' => '[iíìï]', 'o' => '[oóòö]', 'u' => '[uúùü]'];
        $out = '';
        foreach (preg_split('//u', $term, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $base = strtr(mb_strtolower($char, 'UTF-8'), ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
                'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ñ' => 'n']);
            $out .= $map[$base] ?? preg_quote($char, '/');
        }

        return $out;
    }
}
