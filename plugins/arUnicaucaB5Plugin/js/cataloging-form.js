// Formulario de catalogación (sfIsadPlugin/editSuccess.php del plugin). Solo actúa si la página tiene .ah-cataloging-form.
(($) => {
  "use strict";

  $(() => {
    const $root = $(".ah-cataloging-form");
    if (!$root.length) {
      return;
    }

    // Enlaces "editar área" desde la ficha (#identity-collapse, #context-collapse...): esas áreas están dentro de los
    // campos adicionales, así que hay que abrir también la sección que las contiene.
    const additional = document.getElementById("ah-additional-fields");
    if (additional && window.location.hash) {
      let target = null;
      try {
        target = document.querySelector(window.location.hash);
      } catch (e) {
        target = null;
      }
      if (target && additional.contains(target)) {
        bootstrap.Collapse.getOrCreateInstance(additional, { toggle: false }).show();
      }
    }

    // "Más opciones de fecha": expansión propia de cada fila de fecha (_catalogingDates.php). Solo alterna `hidden` y
    // aria-expanded; el select que contiene sigue enviándose cerrado.
    const setMore = (toggle, open) => {
      const more = toggle.parentElement.querySelector(".ah-date-more");
      more.hidden = !open;
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    };
    $root.on("click", ".ah-date-more-toggle", function () {
      setMore(this, this.getAttribute("aria-expanded") !== "true");
    });

    // multiRow.js clona la última fila sin renumerar ids que no sean de controles: la fila nueva recibe un id propio para su
    // expansión y queda abierta solo si su tipo (copiado de la fila clonada) no es el predeterminado.
    let clones = 0;
    $root.on("click", ".ah-dates .multi-row-add", function () {
      const table = this.closest("table");
      setTimeout(() => {
        const row = table.querySelector("tbody > tr:last-child");
        const toggle = row && row.querySelector(".ah-date-more-toggle");
        if (!toggle) {
          return;
        }
        const id = `ah-date-more-new-${++clones}`;
        row.querySelector(".ah-date-more").id = id;
        toggle.setAttribute("aria-controls", id);
        setMore(toggle, row.querySelector(".ah-date-more select").value !== table.dataset.defaultType);
      }, 0);
    });
  });
})(jQuery);
