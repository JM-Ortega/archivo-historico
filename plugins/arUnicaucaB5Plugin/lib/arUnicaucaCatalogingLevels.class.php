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
