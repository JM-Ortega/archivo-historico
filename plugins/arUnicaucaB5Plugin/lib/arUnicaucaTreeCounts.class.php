<?php

/**
 * Conteos del árbol jerárquico «Fondos» (KAN-139): cuántos fondos, series, subseries, secciones y asuntos hay.
 *
 * Alcance: solo lee la base (nunca escribe) y no modifica código de AtoM. Aporta (1) el resumen por nivel de toda la
 * colección y (2) la insignia con el número de hijos directos de cada rama, que se inserta en el <li> que ya genera
 * `render_treeview_node()` de AtoM, sin tocar su marcado original. Si algo falla al contar, el nodo sale como en AtoM.
 *
 * Reglas de los números:
 * - Los textos concuerdan con la cantidad: «1 Fondo», «5 Subseries», «32 Secciones», «7.496 Asuntos» (singular con 1, plural
 *   con cualquier otra cantidad). Los nombres de nivel salen de los términos de AtoM, así que si el Archivo cambia un nombre
 *   el árbol lo refleja sin tocar código.
 * - Un visitante sin sesión solo cuenta descripciones publicadas (no se filtra cuántos borradores hay); con sesión se cuenta todo.
 *
 * Contrato público:
 *   plural(string $nombre, string $culture): plural del nombre de un nivel en español («Sección» -> «Secciones»).
 *   label(int $n, string $nombre, string $culture): «1 Fondo» / «5 Subseries».
 *   format(int $n, string $culture): número con separador de miles («7.496»).
 *   collectionSummary(int $rootId): lista de [nombre, n] por nivel, en el orden de la jerarquía; vacía si no hay datos.
 *   childrenSummary(int $parentId): lista de [nombre, n] de los hijos directos agrupados por nivel.
 *   node($item, array $classes, array $options): igual que render_treeview_node() de AtoM, con la insignia de hijos.
 *   decorate(string $html, $item): el <li> con la insignia de hijos añadida antes de </li> (o el mismo html si no aplica).
 */
class arUnicaucaTreeCounts
{
    private static $children = [];
    private static $names = [];

    public static function plural($name, $culture = 'es')
    {
        $name = trim((string) $name);
        if ('' === $name) {
            return '';
        }
        if (0 !== strpos($culture, 'es')) {
            return $name.('s' === substr($name, -1) ? '' : 's');
        }
        if (false !== strpos($name, ' ')) {
            // «Unidad documental» -> «Unidades documentales»: se pluraliza cada palabra salvo las partículas.
            $skip = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'o', 'en'];
            $words = explode(' ', $name);
            foreach ($words as $i => $word) {
                if (!in_array(mb_strtolower($word), $skip, true) && mb_strlen($word) > 2) {
                    $words[$i] = self::pluralWord($word);
                }
            }

            return implode(' ', $words);
        }

        return self::pluralWord($name);
    }

    private static function pluralWord($word)
    {
        $last = mb_strtolower(mb_substr($word, -1));
        if ('s' === $last && mb_strlen($word) > 3 && !preg_match('/[áéíóú]s$/ui', $word)) {
            return $word; // «Series», «Crisis»: ya terminan en -s sin acento final.
        }
        if (preg_match('/^[ií]tem$/ui', $word)) {
            return $word.'s'; // «Ítem» es un nivel estándar de AtoM: su plural es «ítems», no «ítemes».
        }
        if (preg_match('/ón$/ui', $word)) {
            return mb_substr($word, 0, -2).'ones'; // Sección -> Secciones.
        }
        if ('z' === $last) {
            return mb_substr($word, 0, -1).'ces';
        }
        if (false !== mb_strpos('aeiouáéó', $last)) {
            return $word.'s';
        }

        return $word.'es';
    }

    public static function label($n, $name, $culture = 'es')
    {
        $name = 1 === (int) $n ? trim((string) $name) : self::plural($name, $culture);

        return self::format($n, $culture).' '.$name;
    }

    public static function format($n, $culture = 'es')
    {
        return 0 === strpos($culture, 'es') ? number_format((int) $n, 0, ',', '.') : number_format((int) $n);
    }

    public static function collectionSummary($rootId)
    {
        $root = QubitInformationObject::getById((int) $rootId);
        if (!$root || !isset($root->lft, $root->rgt)) {
            return [];
        }
        $rows = QubitPdo::fetchAll(
            'SELECT io.level_of_description_id AS lod, COUNT(*) AS n FROM information_object io'.self::publishedJoin().
            ' WHERE io.lft >= ? AND io.rgt <= ? AND io.level_of_description_id IS NOT NULL'.
            ' GROUP BY io.level_of_description_id ORDER BY MIN(io.lft)',
            [$root->lft, $root->rgt]
        );

        return self::named($rows);
    }

    public static function childrenSummary($parentId)
    {
        $parentId = (int) $parentId;
        if (!array_key_exists($parentId, self::$children)) {
            $rows = QubitPdo::fetchAll(
                'SELECT io.level_of_description_id AS lod, COUNT(*) AS n FROM information_object io'.self::publishedJoin().
                ' WHERE io.parent_id = ? GROUP BY io.level_of_description_id ORDER BY MIN(io.lft)',
                [$parentId]
            );
            self::$children[$parentId] = self::named($rows);
        }

        return self::$children[$parentId];
    }

    public static function node($item, array $classes = [], array $options = [])
    {
        return self::decorate(render_treeview_node($item, $classes, $options), $item);
    }

    public static function decorate($html, $item)
    {
        try {
            $raw = sfOutputEscaper::unescape($item);
            if (!$raw instanceof QubitInformationObject || false === ($end = strrpos($html, '</li>'))) {
                return $html;
            }
            $groups = self::childrenSummary($raw->id);
            if (!$groups) {
                return $html;
            }
            $culture = sfContext::getInstance()->user->getCulture();
            $total = array_sum(array_column($groups, 1));
            if (1 === count($groups)) {
                $name = 1 === $total ? $groups[0][0] : self::plural($groups[0][0], $culture);
                $text = mb_strtolower($name);
                $title = self::label($total, $groups[0][0], $culture);
            } else {
                $text = 1 === $total ? 'elemento' : 'elementos';
                $parts = [];
                foreach ($groups as $group) {
                    $parts[] = self::label($group[1], $group[0], $culture);
                }
                $title = implode(', ', $parts);
            }
            $badge = '<span class="ah-tree-count" title="'.esc_entities($title).'"><span class="ah-tree-count-n">'
                .self::format($total, $culture).'</span> <span class="ah-tree-count-t">'.esc_entities($text).'</span></span>';

            return substr($html, 0, $end).$badge.substr($html, $end);
        } catch (Exception $e) {
            return $html; // El árbol sigue funcionando como en AtoM si el conteo falla.
        }
    }

    private static function publishedJoin()
    {
        if (sfContext::getInstance()->user->isAuthenticated()) {
            return '';
        }

        return ' JOIN status st ON st.object_id = io.id AND st.type_id = '.(int) QubitTerm::STATUS_TYPE_PUBLICATION_ID
            .' AND st.status_id = '.(int) QubitTerm::PUBLICATION_STATUS_PUBLISHED_ID;
    }

    private static function named($rows)
    {
        $culture = sfContext::getInstance()->user->getCulture();
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->lod;
            $key = $culture.':'.$id;
            if (!array_key_exists($key, self::$names)) {
                $term = QubitTerm::getById($id);
                self::$names[$key] = $term ? trim((string) $term->getName(['culture' => $culture, 'cultureFallback' => true])) : '';
            }
            if ('' !== self::$names[$key]) {
                $out[] = [self::$names[$key], (int) $row->n];
            }
        }

        return $out;
    }
}
