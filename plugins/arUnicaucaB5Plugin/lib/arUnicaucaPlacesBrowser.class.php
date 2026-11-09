<?php

/*
 * Navegación temática «Lugares» (taxonomy/index de la taxonomía de lugares).
 *
 * Datos de solo lectura que necesita la vista rediseñada y que la action nativa (TaxonomyIndexAction) no entrega:
 *
 * - quickAccessTerms(): todos los términos de la taxonomía, por orden alfabético, con su número de descripciones
 *   archivísticas, para el panel lateral «Navegar lugares». Usa el mismo índice (QubitTerm en Elasticsearch) y el mismo
 *   conteo (QubitTerm::countRelatedInformationObjects, que respeta borradores/ACL) que la tabla nativa.
 * - pageLinks(): los números de página visibles en la paginación (ventana centrada en la página actual).
 *
 * No modifica la consulta, la ordenación ni la paginación de AtoM: la tabla sigue saliendo del $pager de la action.
 */
class arUnicaucaPlacesBrowser
{
    // Tope del panel lateral: evita una consulta y N conteos sin límite si la taxonomía crece mucho.
    public const QUICK_ACCESS_LIMIT = 250;

    /**
     * @return array<int, array{name: string, slug: string, count: int}>
     */
    public static function quickAccessTerms(int $taxonomyId, string $culture, int $limit = self::QUICK_ACCESS_LIMIT): array
    {
        try {
            $query = new \Elastica\Query();
            $query->setSize($limit);

            $bool = new \Elastica\Query\BoolQuery();
            $term = new \Elastica\Query\Term();
            $term->setTerm('taxonomyId', $taxonomyId);
            $bool->addMust($term);
            $query->setQuery($bool);
            $query->setSort([sprintf('i18n.%s.name.alphasort', $culture) => 'asc']);

            $resultSet = QubitSearch::getInstance()->index->getIndex('QubitTerm')->search($query);
        } catch (Exception $e) {
            // El panel lateral es complementario: si el índice no responde, la vista principal sigue funcionando.
            return [];
        }

        $terms = [];
        foreach ($resultSet->getResults() as $hit) {
            $doc = $hit->getData();
            $name = (string) get_search_i18n($doc, 'name', ['allowEmpty' => false]);
            if ('' === trim($name) || empty($doc['slug'])) {
                continue;
            }

            $terms[] = [
                'name' => $name,
                'slug' => $doc['slug'],
                'count' => (int) QubitTerm::countRelatedInformationObjects($hit->getId()),
            ];
        }

        return $terms;
    }

    /**
     * Números de página a mostrar: como mucho $items, centrados en la página actual.
     *
     * @return int[]
     */
    public static function pageLinks(int $page, int $lastPage, int $items = 5): array
    {
        $lastPage = max(1, $lastPage);
        $page = min(max(1, $page), $lastPage);
        $start = max(1, $page - intdiv($items, 2));
        $end = min($lastPage, $start + $items - 1);
        $start = max(1, $end - $items + 1);

        return range($start, $end);
    }
}
