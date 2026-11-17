/* OCULTO-042
   SERVÍA PARA: dejar en pantalla, una sobre otra, todas las alertas que aparecen al pulsar «Exportar» en la exportación del portapapeles.
   MOTIVO: las alertas repetidas se acumulaban; ahora, al aparecer una alerta nueva, se elimina la anterior (eliminada por script).
   Código original: clipboard.js (upstream) solo agrega cada alerta nueva al inicio de la página, sin quitar las anteriores.
*/

// Alertas de la exportación del portapapeles: se muestran en español y cada alerta nueva reemplaza a la anterior.
//
// Los avisos que salen al pulsar «Exportar» llegan en la respuesta del servidor (clipboard/exportAction, upstream) ya armados
// en inglés, y clipboard.js los agrega al inicio de #wrapper sin quitar los anteriores. Este script se ejecuta cuando termina
// la petición de exportación: traduce las alertas que conoce y deja solo la última.
(function () {
  "use strict";

  const $ = window.jQuery;
  if (!$) {
    return;
  }

  const escapeHtml = (value) =>
    String(value).replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;",
    })[char]);

  const messages = [
    {
      test: /export package is being built/,
      html: (alert) => {
        const entity = (alert.textContent.match(/Your (.+?) export package is being built/) || [])[1] || "";
        const link = alert.querySelector("a.alert-link");
        const href = link ? link.getAttribute("href") : "#";
        const progress = /job management page/.test(alert.textContent)
          ? `La <strong><a class="alert-link" href="${escapeHtml(href)}">página de administración de procesos</a></strong> mostrará el avance y un enlace de descarga cuando termine.`
          : 'Por favor, <strong><a class="alert-link" data-action="refresh" href="#">actualice la página</a></strong> para ver el avance y un enlace de descarga cuando termine.';

        return `<p><strong>Su paquete de exportación de ${escapeHtml(entity)} se está generando.</strong> ${progress}</p>`
          + "<p><strong>Nota:</strong> AtoM puede eliminar los paquetes de exportación pasado cierto tiempo para liberar espacio de almacenamiento. Cuando su exportación esté lista, descárguela lo antes posible.</p>";
      },
    },
    {
      test: /No records were exported for your current selection/,
      html: () => 'No se exportó ningún registro con su selección actual. Por favor, <a class="alert-link" data-action="refresh" href="#">actualice la página y elija otras opciones de exportación</a>.',
    },
    {
      test: /Invalid export options/,
      html: () => "Opciones de exportación no válidas.",
    },
    {
      test: /The clipboard is empty for this entity type/,
      html: () => "El portapapeles está vacío para este tipo de entidad.",
    },
    {
      test: /You are not allowed to export this entity type/,
      html: () => "No tiene permiso para exportar este tipo de entidad.",
    },
    {
      test: /No Gearman worker available/,
      html: () => "No hay un proceso de fondo disponible para ejecutar la exportación. Intente de nuevo en unos minutos o avise al administrador.",
    },
  ];

  const translateAlert = (alert) => {
    const entry = messages.find((message) => message.test.test(alert.textContent));
    if (!entry) {
      return;
    }

    // El contenido nuevo se arma antes de vaciar la alerta, porque usa su enlace; el botón de cerrar se conserva.
    const html = entry.html(alert);
    const close = alert.querySelector(".btn-close");
    const closeElement = close && close.parentElement !== alert ? close.closest("a") : close;

    Array.from(alert.childNodes).forEach((node) => {
      if (node !== closeElement) {
        alert.removeChild(node);
      }
    });
    alert.insertAdjacentHTML("afterbegin", html);
  };

  $(document).ajaxComplete((event, xhr, settings) => {
    if (!settings || !/clipboard\/export/.test(settings.url || "")) {
      return;
    }

    const $wrapper = $("body > #wrapper");
    const $newest = $wrapper.children(".alert").first();
    if (!$newest.length) {
      return;
    }

    translateAlert($newest[0]);
    $wrapper.children(".alert[data-uc-export-alert]").not($newest).remove();
    $newest.attr("data-uc-export-alert", "1");
  });
})();
