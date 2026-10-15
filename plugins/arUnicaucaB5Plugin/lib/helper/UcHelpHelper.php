<?php

/*
 * Ayuda contextual (toggletip) del theme: un botón de información junto a la etiqueta de un campo que muestra un texto breve.
 * Se abre con el ratón (al pasar o al hacer clic), con el teclado (al enfocar el botón, o con Intro/Espacio) y se cierra con
 * Escape, al salir o al hacer clic fuera. Comportamiento: js/help.js. Estilos: scss/_help.scss.
 *
 * Uso en una vista: <?php use_helper('UcHelp'); ?> ... uc_help('identifier-help-tip', __('Signatura'), __('Texto...'));
 * El control del campo debe incluir el id en su aria-describedby para que el lector de pantalla lea la ayuda al enfocarlo.
 */

/**
 * @param string $id    id del texto de ayuda (único en la página)
 * @param string $label nombre del campo, para el nombre accesible del botón
 * @param string $text  texto de ayuda (HTML ya escapado o texto plano de confianza)
 */
function uc_help(string $id, string $label, string $text): string
{
    $buttonLabel = __('Más información: %1%', ['%1%' => $label]);

    return '<span class="uc-help" data-uc-help>'
        .'<button type="button" class="uc-help-toggle" aria-expanded="false" aria-controls="'.$id.'" aria-label="'
        .htmlspecialchars(strip_tags($buttonLabel), ENT_QUOTES, 'UTF-8').'">'
        .'<i class="fa-regular fa-circle-question" aria-hidden="true"></i>'
        .'</button>'
        .'<span class="uc-help-bubble" id="'.$id.'" role="tooltip">'.$text.'</span>'
        .'</span>';
}
