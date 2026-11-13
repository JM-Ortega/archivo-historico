<?php

/**
 * Relaciones Remitente / Destinatario de una descripción archivística.
 *
 * Cada relación es un QubitEvent de la descripción con un actor y sin fecha. Su tipo es un término de la taxonomía de tipos
 * de evento identificado por taxonomía + `code` (lo provisiona el reconcile del proyecto, config/atom/reconcile-terms.php);
 * nunca por id ni por nombre. Solo se leen y escriben eventos de esos dos tipos: Creación, Acumulación, los eventos de fecha
 * y cualquier otro tipo de evento quedan intactos.
 *
 * Los actores llegan del autocomplete nativo de AtoM como URLs de recurso: un actor elegido de la lista conserva su
 * identidad y un nombre nuevo llega como el actor que el propio autocomplete acaba de crear. Aquí no se busca, compara,
 * normaliza ni fusiona ningún nombre, y nunca se modifica un actor: solo se enlazan o desenlazan.
 */
class arUnicaucaCatalogingRoleEvents
{
    /** Campo del formulario → code del tipo de evento. */
    public const ROLES = [
        'remitentes' => 'archivo-historico:remitente',
        'destinatarios' => 'archivo-historico:destinatario',
    ];

    /**
     * Tipo de evento del proyecto por taxonomía + code (comparación binaria). Falla si no existe exactamente uno: sin él no
     * hay forma segura de leer ni escribir la relación.
     */
    public static function getType(string $code): QubitTerm
    {
        $ids = QubitPdo::fetchAll(
            'SELECT id FROM term WHERE taxonomy_id = ? AND code = ? COLLATE utf8mb4_bin',
            [QubitTaxonomy::EVENT_TYPE_ID, $code],
            ['fetchMode' => PDO::FETCH_COLUMN]
        );
        if (1 !== count($ids) || null === $type = QubitTerm::getById($ids[0])) {
            throw new sfException(sprintf('Event type "%s" must exist exactly once (found %d); run the project reconcile.', $code, count($ids)));
        }

        return $type;
    }

    /**
     * Eventos con actor de ese tipo que pertenecen a la descripción, en orden de alta. Una descripción sin guardar no tiene
     * ninguno (sin id no se consulta: OBJECT_ID IS NULL alcanzaría eventos ajenos).
     *
     * @return QubitEvent[]
     */
    public static function getEvents(QubitInformationObject $resource, QubitTerm $type): array
    {
        if (!isset($resource->id)) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->add(QubitEvent::OBJECT_ID, $resource->id);
        $criteria->add(QubitEvent::TYPE_ID, $type->id);
        $criteria->add(QubitEvent::ACTOR_ID, null, Criteria::ISNOTNULL);
        $criteria->addAscendingOrderByColumn(QubitEvent::ID);

        $events = [];
        foreach (QubitEvent::get($criteria) as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Callback de sfValidatorCallback para los campos de rol: cada valor debe ser la URL de un actor existente.
     *
     * @return null|QubitActor[] actores indexados por id (sin repetidos), o null si no se envió ninguno
     */
    public static function cleanActors(sfValidatorBase $validator, $value, array $arguments = [])
    {
        if (null === $value || [] === $value) {
            return null;
        }

        $actors = [];
        foreach ((array) $value as $item) {
            $actor = is_string($item) ? arUnicaucaCatalogingResubmit::resource($item) : null;
            if (!$actor instanceof QubitActor) {
                throw new sfValidatorError($validator, 'invalid', ['value' => $item]);
            }
            $actors[$actor->id] = $actor;
        }

        return $actors;
    }

    /**
     * Deja las relaciones de ese tipo iguales a $actors: conserva las que siguen, quita las que ya no están y crea las que
     * faltan (se guardan con la descripción). $events son los eventos que la descripción tenía al abrir el formulario.
     *
     * Quitar una relación borra su evento. Si ese evento tuviera fecha (datos que no nacieron en este formulario), no se
     * borra: solo se desenlaza el actor y la fecha se conserva, como hace AtoM con los productores.
     *
     * @param QubitEvent[] $events
     * @param QubitActor[] $actors indexados por id
     */
    public static function sync(QubitInformationObject $resource, QubitTerm $type, array $events, array $actors): void
    {
        $missing = $actors;
        foreach ($events as $event) {
            if (isset($actors[$event->actorId])) {
                unset($missing[$event->actorId]);

                continue;
            }

            // La descripción se reindexa al guardarse.
            $event->indexOnSave = false;
            if (
                0 === strlen((string) $event->getDate(['cultureFallback' => true]))
                && null === $event->startDate
                && null === $event->endDate
            ) {
                $event->delete();
            } else {
                $event->actorId = null;
                $event->save();
            }
        }

        foreach ($missing as $actor) {
            $event = new QubitEvent();
            $event->typeId = $type->id;
            $event->actorId = $actor->id;

            $resource->eventsRelatedByobjectId[] = $event;
        }
    }
}
