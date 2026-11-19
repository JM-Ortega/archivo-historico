<?php

class ClipboardExportCheckAction extends sfAction
{
    public static $ALERT_TYPES = [
        QubitTerm::JOB_STATUS_IN_PROGRESS_ID => 'info',
        QubitTerm::JOB_STATUS_COMPLETED_ID => 'success',
        QubitTerm::JOB_STATUS_ERROR_ID => 'error',
    ];

    public function execute($request)
    {
        ProjectConfiguration::getActive()->loadHelpers('Qubit');

        $alerts = $missingTokens = [];
        $tokens = $request->getParameter('tokens', []);
        $baseUrl = rtrim($request->getUriPrefix(), '/');

        foreach ($tokens as $token) {
            if (!ctype_xdigit($token) || 32 != strlen($token)) {
                $missingTokens[] = $token;

                continue;
            }

            $job = QubitJob::getByUserTokenProperty($token);

            if (!isset($job)) {
                $missingTokens[] = $token;

                continue;
            }

            $message = $this->context->i18n->__(
                '%1% (started: %2%, status: %3%).',
                [
                    '%1%' => (string) $job,
                    '%2%' => $job->getCreationDateString(),
                    '%3%' => $job->getStatusString(),
                ]
            );

            if (isset($job->downloadPath) && QubitTerm::JOB_STATUS_COMPLETED_ID == $job->statusId) {
                $downloadUrl = $baseUrl.'/'.ltrim($job->downloadPath, '/');
                $message .= $this->context->i18n->__(
                    ' %1%Download%2% (%3%)',
                    [
                        '%1%' => sprintf('<a href="%s">', htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8')),
                        '%2%' => '</a>',
                        '%3%' => hr_filesize(filesize($job->downloadPath)),
                    ]
                );
            } else {
                $message .= ' '.$this->context->i18n->__(
                    '%1%Refresh the page%2% for progress updates.',
                    [
                        '%1%' => '<a data-action="refresh" href="#"">',
                        '%2%' => '</a>',
                    ]
                );
            }

            $deleteUrl = $this->context->controller->genUrl('jobs/delete?token='.$token);
            $deleteUrl = QubitTerm::JOB_STATUS_COMPLETED_ID == $job->statusId ? $deleteUrl : null;

            $alerts[] = [
                'type' => self::$ALERT_TYPES[$job->statusId],
                'message' => $message,
                'deleteUrl' => $deleteUrl,
                'token' => $token,
            ];
        }

        $this->response->setHttpHeader('Content-Type', 'application/json; charset=utf-8');
        $this->response->setStatusCode(200);

        return $this->renderText(json_encode(['alerts' => $alerts, 'missingTokens' => $missingTokens]));
    }
}
