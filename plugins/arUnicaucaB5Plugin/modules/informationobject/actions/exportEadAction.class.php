<?php

class InformationObjectExportEadAction extends sfAction
{
    public function execute($request)
    {
        if (!$this->context->user->isAuthenticated()) {
            $this->forward404();
        }

        $resource = $this->getRoute()->resource;

        if (!$resource instanceof QubitInformationObject || !QubitAcl::check($resource, 'read')) {
            $this->forward404();
        }

        QubitJob::runJob('arInformationObjectXmlExportJob', [
            'params' => [
                'fromClipboard' => true,
                'slugs' => [$resource->slug],
                'levels' => [],
                'objectType' => 'informationobject',
                'nonVisibleElementsIncluded' => false,
            ],
        ]);

        $this->redirect(['module' => 'jobs', 'action' => 'browse']);
    }
}
