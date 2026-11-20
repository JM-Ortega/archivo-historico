<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Information object advanced search component.
 */
class InformationObjectAdvancedSearchComponent extends sfComponent
{
    public function execute($request)
    {
        // Default to hiding the advanced search panel
        $this->showAdvanced = false;
        if (filter_var($request->showAdvanced, FILTER_VALIDATE_BOOLEAN)) {
            $this->showAdvanced = true;
        }

        // Default to inclusive date range type
        $this->rangeType = 'inclusive';
        if (isset($request->rangeType)) {
            $this->rangeType = $request->rangeType;
        }

        // Default to showing finding aid search fields
        $this->findingAidsEnabled = true;
        if ('1' !== sfConfig::get('app_findingAidsEnabled', '1')) {
            $this->findingAidsEnabled = false;
        }

        // Check "archival history" field visiblity
        $this->showArchivalHistory = false;
        if (
            (
                'rad' == $template
                && check_field_visibility(
                    'app_element_visibility_rad_archival_history'
                )
            ) || (
                'isad' == $template
                && check_field_visibility(
                    'app_element_visibility_isad_archival_history'
                )
            ) || (
                'isad' != $template && 'rad' != $template
            )
        ) {
            $this->showArchivalHistory = true;
        }

        // Check visible fields settings
        $this->showCopyright = sfConfig::get('app_toggleCopyrightFilter');
        $this->showMaterial = sfConfig::get('app_toggleMaterialFilter');

        // Use inclusive dates?
        $this->inclusiveDates = ('inclusive' == $this->rangeType);

        $this->fieldTypes = $this->getFieldTypes();
    }

    /**
     * Override de la lista de campos de la búsqueda avanzada Copiado de
     * upstream/atom/apps/qubit/modules/informationobject/actions/advancedSearchComponent.class.php
     * y modificado solo en este método.
     *
     * Se dejan únicamente los campos que maneja el catálogo del Archivo
     * Central del Cauca. El valor interno (clave del arreglo) es el campo
     * de AtoM; solo cambia la etiqueta visible:
     *   - identifier      -> "Signatura"            (renombrado)
     *   - title           -> "Título"               (sin cambio)
     *   - scopeAndContent -> "Contenido"            (renombrado)
     *   - place           -> "Lugar de procedencia" (renombrado)
     *   - extentAndMedium -> "Folios"               (renombrado)
     */
    public function getFieldTypes()
    {
        sfProjectConfiguration::getActive()->loadHelpers(['I18N']);

        $fieldTypes = [
            '' => __('Any field'),
            'identifier' => __('Signatura'),
            'title' => __('Title'),
            'scopeAndContent' => __('Contenido'),
            'place' => __('Lugar de procedencia'),
            'extentAndMedium' => __('Folios'),
            'name' => __('Remitente o destinatario'),
        ];

        /*
         * --- Opciones originales de AtoM ocultas por UNICAUCA ---
         *
         * OCULTO-052: Historia archivística
         * if ($this->showArchivalHistory) {
         *     $fieldTypes['archivalHistory'] = __('Archival history');
         * }
         *
         * $fieldTypes += [
         *     'scopeAndContent' => __('Scope and content'),    // renombrado a "Contenido"
         *     'extentAndMedium' => __('Extent and medium'),    // renombrado a "Folios"
         *     'subject' => __('Subject access points'),        // OCULTO-053
         *     'name' => __('Name access points'),              // renombrado a "Remitente o destinatario"
         *     'place' => __('Place access points'),            // renombrado a "Lugar de procedencia"
         *     'genre' => __('Genre access points'),            // OCULTO-054
         *     'identifier' => __('Identifier'),                // renombrado a "Signatura"
         *     'referenceCode' => __('Reference code'),         // OCULTO-055
         *     'digitalObjectTranscript' => __('Digital object text'), // OCULTO-056
         *     'creator' => __('Creator'),                      // OCULTO-057
         * ];
         *
         * if ($this->findingAidsEnabled) {
         *     $fieldTypes += [
         *         'findingAidTranscript' => __('Finding aid text'),                       // OCULTO-058
         *         'allExceptFindingAidTranscript' => __('Any field except finding aid text'), // OCULTO-059
         *     ];
         * }
         */

        return $fieldTypes;
    }
}
