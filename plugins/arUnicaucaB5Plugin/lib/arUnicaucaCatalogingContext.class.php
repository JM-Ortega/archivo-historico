<?php

/**
 * Ruta jerárquica de solo lectura del formulario de catalogación ("Fondo (Fondo) › Sección (Sección) › ..."). Usa los
 * ancestros nativos; no calcula ni cambia el padre (el padre lo determina la operación nativa: `parent` o la edición).
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
