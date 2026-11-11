<?php

/*
 * Contadores de la vista «Administrar trabajos» (jobs/browse): número de trabajos de cada pestaña (todos, activos y fallidos).
 *
 * La acción JobsBrowseAction (upstream) solo entrega a la plantilla los trabajos del filtro actual, así que el número de las
 * otras pestañas se calcula aquí, sin tocar upstream. Usa los mismos criterios que esa acción: «activos» son los que están en
 * proceso, «fallidos» los que terminaron en error, y quien no es administrador solo cuenta sus propios trabajos.
 *
 * Uso en una vista: <?php use_helper('UcJobs'); ?> ... $counts = uc_jobs_counts(); // ['all' => 0, 'active' => 0, 'failed' => 0]
 * Estilos de la insignia: scss/_jobs.scss (.ah-jobs__count).
 */

/**
 * @return array{all: int, active: int, failed: int}
 */
function uc_jobs_counts(): array
{
    $user = sfContext::getInstance()->user;

    $conditions = [];
    $params = [];

    // Igual que JobsBrowseAction: si no es administrador, solo sus trabajos.
    if (!$user->isAdministrator()) {
        $conditions[] = 'job.user_id = ?';
        $params[] = $user->getUserID();
    }

    $count = static function (array $extraConditions = [], array $extraParams = []) use ($conditions, $params): int {
        $where = array_merge($conditions, $extraConditions);

        // El join con object replica el criterio de la acción (QubitJob::ID = QubitObject::ID).
        $sql = 'SELECT COUNT(*) FROM job JOIN object ON job.id = object.id';
        if ($where) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        return (int) QubitPdo::fetchColumn($sql, array_merge($params, $extraParams));
    };

    return [
        'all' => $count(),
        'active' => $count(['job.status_id = ?'], [QubitTerm::JOB_STATUS_IN_PROGRESS_ID]),
        'failed' => $count(['job.status_id = ?'], [QubitTerm::JOB_STATUS_ERROR_ID]),
    ];
}
