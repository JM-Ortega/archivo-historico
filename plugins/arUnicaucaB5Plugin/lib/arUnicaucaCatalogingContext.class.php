<?php

/**
 * Ruta jerárquica de solo lectura del formulario de catalogación ("Fondo (Fondo) › Sección (Sección) › ..."). Usa los
 * ancestros nativos; no calcula ni cambia el padre (el padre lo determina la operación nativa: `parent` o la edición).
 *
 * También arma los datos de la cascada "Pertenece a" del alta (parentCascade) y valida el `parent` que envía
 * (chosenParent).
 */
class arUnicaucaCatalogingContext
{
    /**
     * @param QubitInformationObject|null $parent padre efectivo de una descripción nueva (alta o duplicado)
     * @param QubitInformationObject|null $edited descripción existente que se edita
     *
     * @return array[] elementos ['label' => string, 'level' => ?string]; la lista vacía significa nivel superior
     */
    public static function ancestors(?QubitInformationObject $parent, ?QubitInformationObject $edited): array
    {
        $tip = $edited ?? $parent;
        if (null === $tip) {
            return [];
        }

        $items = [];
        foreach ($tip->getAncestors()->andSelf()->orderBy('lft') as $io) {
            if (QubitInformationObject::ROOT_ID === $io->id || (null !== $edited && $io->id === $edited->id)) {
                continue;
            }
            $items[] = ['label' => self::label($io), 'level' => self::level($io)];
        }

        return $items;
    }

    /** Descripción existente que se edita: su propio elemento de la ruta. */
    public static function current(QubitInformationObject $io): array
    {
        return ['label' => self::label($io), 'level' => self::level($io)];
    }

    // --- Cascada "Pertenece a" del alta (Fondo → Sección → Serie → Subserie) -------------------------------------------
    // El catalogador elige un fondo y, si quiere, una descripción dentro de él, y así hacia abajo. Ningún paso es
    // obligatorio: la nueva descripción queda dentro del último elegido o, si no se elige nada, en el nivel superior. La
    // cascada no añade campos al POST: js/cataloging-parent.js solo cambia el valor del hidden nativo `parent`, que AtoM ya
    // usa en el alta. Sin JavaScript no se muestra y el alta funciona como siempre. (Vive en esta clase, y no en una nueva,
    // para no depender de `php symfony cc`: el autoload de Symfony está cacheado y no vería una clase nueva en lib/.)

    /**
     * @return null|array ['root' => URL del nivel superior, 'levels' => posición → nombre del nivel,
     *                    'items' => [['id', 'parent', 'level' (posición 0-3), 'label', 'url'], ...] en orden del árbol];
     *                    null si no hay ninguna descripción con esos niveles (no hay nada que elegir)
     */
    public static function parentCascade(): ?array
    {
        $terms = arUnicaucaCatalogingLevels::archiveTerms(QubitTerm::getLevelsOfDescription());
        if ([] === $terms) {
            return null;
        }

        $positions = $levels = [];
        foreach ($terms as $position => $term) {
            $positions[$term->id] = $position;
            $levels[$position] = (string) $term->getName(['cultureFallback' => true]);
        }

        $context = sfContext::getInstance();
        $culture = $context->getUser()->getCulture();
        $ids = array_keys($positions);
        $rows = QubitPdo::fetchAll(
            'SELECT io.id, io.parent_id, io.identifier, io.level_of_description_id, s.slug,
                COALESCE(NULLIF(cur.title, \'\'), src.title) AS title
              FROM information_object io
              JOIN slug s ON s.object_id = io.id
              LEFT JOIN information_object_i18n cur ON cur.id = io.id AND cur.culture = ?
              LEFT JOIN information_object_i18n src ON src.id = io.id AND src.culture = io.source_culture
              WHERE io.level_of_description_id IN ('.implode(', ', array_fill(0, count($ids), '?')).')
              ORDER BY io.lft',
            array_merge([$culture], $ids),
            ['fetchMode' => PDO::FETCH_ASSOC]
        );
        if ([] === $rows) {
            return null;
        }

        // Las URL se arman como las genera el routing para una descripción: se genera la de una y se cambia el slug.
        $routing = $context->getRouting();
        $sample = QubitInformationObject::getById($rows[0]['id']);
        $sampleUrl = $routing->generate(null, [$sample, 'module' => 'informationobject']);
        $prefix = substr($sampleUrl, 0, -strlen($rows[0]['slug']));
        $root = QubitInformationObject::getById(QubitInformationObject::ROOT_ID);

        $untitled = $context->i18n->__('Sin título');
        $items = [];
        foreach ($rows as $row) {
            $title = trim((string) $row['title']);
            $identifier = trim((string) $row['identifier']);
            if ('' === $title) {
                $label = '['.('' !== $identifier ? $identifier : $untitled).']';
            } else {
                $label = '' !== $identifier ? $title.' ('.$identifier.')' : $title;
            }

            $items[] = [
                'id' => (int) $row['id'],
                'parent' => (int) $row['parent_id'],
                'level' => $positions[$row['level_of_description_id']],
                'label' => $label,
                'url' => $prefix.$row['slug'],
            ];
        }

        return [
            'root' => $routing->generate(null, [$root, 'module' => 'informationobject']),
            'levels' => $levels,
            'items' => $items,
        ];
    }

    /**
     * Padre elegido en el alta, validado: debe ser una descripción existente sobre la que el usuario pueda crear. Si no lo
     * es (valor manipulado, descripción borrada...), se usa el padre con el que se abrió el formulario.
     *
     * @param string                 $value    valor enviado en `parent` (URL o slug de la descripción)
     * @param QubitInformationObject $fallback padre con el que se abrió el formulario
     */
    public static function chosenParent(string $value, QubitInformationObject $fallback): QubitInformationObject
    {
        $resource = arUnicaucaCatalogingResubmit::resource($value);
        if ($resource instanceof QubitInformationObject && QubitAcl::check($resource, 'create')) {
            return $resource;
        }

        return $fallback;
    }

    // Texto visual seguro: nunca escribe un título sintético en la BD.
    private static function label(QubitInformationObject $io): string
    {
        $title = trim((string) $io->getTitle(['cultureFallback' => true]));
        if ('' !== $title) {
            return $title;
        }
        $identifier = trim((string) $io->identifier);

        return '' !== $identifier ? '['.$identifier.']' : '['.sfContext::getInstance()->i18n->__('Sin título').']';
    }

    private static function level(QubitInformationObject $io): ?string
    {
        $level = $io->levelOfDescription;
        $name = null === $level ? '' : trim((string) $level->getName(['cultureFallback' => true]));

        return '' !== $name ? $name : null;
    }
}
