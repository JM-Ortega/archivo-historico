<?php

/**
 * Presentación del selector "Nivel de descripción": agrupa las opciones nativas en los niveles que usa el Archivo (en su
 * orden jerárquico) y el resto de niveles de AtoM. Solo reordena las opciones del widget: los valores, la taxonomía y los
 * términos no cambian, y no se elige ningún valor por defecto.
 *
 * Reconocimiento: el término propio por taxonomía + code; los estándar de AtoM no tienen code, así que se reconocen por su
 * nombre en inglés de la instalación (Fonds, Series...). Un término que no se reconozca (p. ej. renombrado) sigue disponible
 * en "Otros niveles".
 */
class arUnicaucaCatalogingLevels
{
    public const PROFILE_GROUPING = 'agrupacion';
    public const PROFILE_UNIT = 'unidad';
    public const PROFILE_GENERAL = 'general';

    private const ARCHIVE_LEVELS = [
        ['en' => 'Fonds'],
        ['code' => 'archivo-historico:seccion'],
        ['en' => 'Series'],
        ['en' => 'Subseries'],
        ['en' => 'File'],
        ['en' => 'Item'],
    ];

    /** OCULTO-051: niveles que se ofrecen al catalogar: las 4 primeras posiciones de ARCHIVE_LEVELS (Fondo a Subserie). */
    private const NEW_RECORD_LEVELS = 4;

    /**
     * @param array $choices opciones nativas del widget: URL del término → QubitTerm (más la opción vacía)
     *
     * @return array opción vacía + dos optgroups (el segundo solo si tiene opciones)
     */
    public static function group(array $choices, string $archiveLabel, string $otherLabel): array
    {
        $archive = array_fill(0, count(self::ARCHIVE_LEVELS), null);
        $empty = $other = [];
        foreach ($choices as $value => $term) {
            if (!$term instanceof QubitTerm) {
                $empty[$value] = $term;

                continue;
            }

            $position = self::position($term);
            if (null !== $position && null === $archive[$position]) {
                $archive[$position] = [$value, $term];
            } else {
                $other[$value] = $term;
            }
        }

        $grouped = [];
        foreach (array_filter($archive) as [$value, $term]) {
            $grouped[$value] = $term;
        }

        $result = $empty;
        if ([] !== $grouped) {
            $result[$archiveLabel] = $grouped;
        }
        if ([] !== $other) {
            $result[$otherLabel] = $other;
        }

        return $result;
    }

    /**
     * OCULTO-051: opciones del selector "Nivel de descripción" limitadas a los niveles que maneja el Archivo: Fondo, Sección,
     * Serie y Subserie (las cuatro primeras posiciones de ARCHIVE_LEVELS), en ese orden y sin optgroups. El resto de niveles
     * de AtoM no se ofrece. Solo cambia lo que se muestra: la taxonomía, los términos y el validador no cambian.
     *
     * Excepción: si la descripción ya tiene otro nivel ($keep, p. ej. al editar una ficha antigua), esa opción se conserva
     * al final para que al guardar no se pierda el nivel.
     *
     * Cómo restaurar: en sfIsadPlugin/actions/editAction.class.php volver a llamar a group() (bloque OCULTO-051).
     *
     * @param array       $choices opciones nativas del widget: URL del término → QubitTerm (más la opción vacía)
     * @param null|string $keep    valor actual del campo, que se conserva aunque no sea uno de los cuatro niveles
     */
    public static function visible(array $choices, ?string $keep = null): array
    {
        $archive = array_fill(0, self::NEW_RECORD_LEVELS, null);
        $result = $kept = [];
        foreach ($choices as $value => $term) {
            if (!$term instanceof QubitTerm) {
                $result[$value] = $term;

                continue;
            }

            $position = self::position($term);
            if (null !== $position && $position < self::NEW_RECORD_LEVELS && null === $archive[$position]) {
                $archive[$position] = [$value, $term];
            } elseif (null !== $keep && '' !== $keep && (string) $value === $keep) {
                $kept[$value] = $term;
            }
        }

        foreach (array_filter($archive) as [$value, $term]) {
            $result[$value] = $term;
        }

        return $result + $kept;
    }

    /**
     * OCULTO-051: los mismos cuatro niveles para la tabla "Agregar nuevos niveles inferiores", en orden jerárquico.
     *
     * @param iterable $terms términos de la taxonomía de niveles (QubitTerm::getLevelsOfDescription())
     *
     * @return QubitTerm[]
     */
    public static function visibleTerms(iterable $terms): array
    {
        return array_values(self::archiveTerms($terms));
    }

    /**
     * Los cuatro niveles del archivo por su posición jerárquica (0 Fondo, 1 Sección, 2 Serie, 3 Subserie). Un nivel que no
     * exista en la instalación simplemente no aparece; las posiciones de los demás no cambian.
     *
     * @param iterable $terms términos de la taxonomía de niveles (QubitTerm::getLevelsOfDescription())
     *
     * @return QubitTerm[] posición → término
     */
    public static function archiveTerms(iterable $terms): array
    {
        $archive = array_fill(0, self::NEW_RECORD_LEVELS, null);
        foreach ($terms as $term) {
            $position = $term instanceof QubitTerm ? self::position($term) : null;
            if (null !== $position && $position < self::NEW_RECORD_LEVELS && null === $archive[$position]) {
                $archive[$position] = $term;
            }
        }

        return array_filter($archive);
    }

    /**
     * Perfil de presentación de un nivel: Fondo, Sección, Serie y Subserie → agrupación; File e Item → unidad documental;
     * cualquier otro nivel (o ninguno, o uno no reconocible) → general. Solo cambia qué campos se priorizan; no toca datos.
     */
    public static function profile(?QubitTerm $term): string
    {
        $position = null === $term ? null : self::position($term);
        if (null === $position) {
            return self::PROFILE_GENERAL;
        }

        return $position < 4 ? self::PROFILE_GROUPING : self::PROFILE_UNIT;
    }

    /**
     * @param array $choices opciones nativas del widget de nivel: URL del término → QubitTerm (más la opción vacía)
     *
     * @return array URL del término → perfil, para los niveles que no son generales; el resto cae en general
     */
    public static function profileMap(array $choices): array
    {
        $map = [];
        foreach ($choices as $value => $term) {
            if ($term instanceof QubitTerm && self::PROFILE_GENERAL !== ($profile = self::profile($term))) {
                $map[(string) $value] = $profile;
            }
        }

        return $map;
    }

    private static function position(QubitTerm $term): ?int
    {
        foreach (self::ARCHIVE_LEVELS as $position => $match) {
            if (isset($match['code']) && $match['code'] === $term->code) {
                return $position;
            }
            if (isset($match['en']) && $match['en'] === $term->getName(['culture' => 'en'])) {
                return $position;
            }
        }

        return null;
    }
}
