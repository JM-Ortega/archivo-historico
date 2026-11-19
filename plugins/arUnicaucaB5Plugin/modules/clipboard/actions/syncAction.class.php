<?php

/*
 * Contador del portapapeles (icono de la cabecera).
 *
 * El portapapeles de AtoM se guarda en el navegador (localStorage) y el contador suma todo lo que hay ahí, aunque la
 * descripción ya no exista (se borró, o la base de datos se reinició) o no se pueda ver. La página del portapapeles, en
 * cambio, solo muestra las descripciones que encuentra. Por eso el icono podía decir 3 y la página «Mostrando 1
 * resultados».
 *
 * Esta acción recibe los slugs de descripciones guardados en el navegador y devuelve solo los que la página del
 * portapapeles mostraría, con la misma búsqueda que clipboard/view (incluido el filtro de borradores). La usa
 * js/clipboard-count.js para quitar del navegador los que sobran y corregir el contador.
 */
class ClipboardSyncAction extends sfAction
{
    private const CHUNK_SIZE = 500;

    public function execute($request)
    {
        $this->response->setHttpHeader('Content-Type', 'application/json; charset=utf-8');

        if (!$request->isMethod('post')) {
            $this->response->setStatusCode(405);

            return $this->renderText(json_encode(['error' => 'Method not allowed']));
        }

        $slugs = $request->getPostParameter('slugs', []);
        if (!is_array($slugs)) {
            $slugs = [];
        }

        $slugs = array_values(array_unique(array_filter(
            array_map('strval', array_filter($slugs, 'is_scalar')),
            'strlen'
        )));

        $found = [];

        foreach (array_chunk($slugs, self::CHUNK_SIZE) as $chunk) {
            $queryBool = new \Elastica\Query\BoolQuery();
            $queryBool->addMust(new \Elastica\Query\Terms('slug', $chunk));
            QubitAclSearch::filterDrafts($queryBool);

            $query = new \Elastica\Query($queryBool);
            $query->setSize(count($chunk));
            $query->setSource(['slug']);

            $resultSet = QubitSearch::getInstance()->index->getIndex('QubitInformationObject')->search($query);

            foreach ($resultSet->getResults() as $hit) {
                $data = $hit->getData();
                if (isset($data['slug'])) {
                    $found[] = $data['slug'];
                }
            }
        }

        $this->response->setStatusCode(200);

        return $this->renderText(json_encode(['informationObject' => array_values(array_unique($found))]));
    }
}
