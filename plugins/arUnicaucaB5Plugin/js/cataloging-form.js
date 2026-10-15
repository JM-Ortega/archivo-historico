// Formulario de catalogación (sfIsadPlugin/editSuccess.php del plugin). Solo actúa si la página tiene .ah-cataloging-form.
(($) => {
  "use strict";

  $(() => {
    const $root = $(".ah-cataloging-form");
    if (!$root.length) {
      return;
    }

    // --- Ficha adaptable (CAT-03) -----------------------------------------------------------------------------------------
    // El nivel elegido decide el perfil (agrupación, unidad, general) y con él qué campos se destacan y cómo se llaman. Aquí
    // solo se cambia `data-ah-profile`, etiquetas y clases: ningún control se mueve, se recrea ni se deshabilita, así que el
    // payload es el mismo. La visibilidad la resuelve el CSS (scss/_cataloging-form.scss) a partir de esos atributos.
    const root = $root[0];
    const form = root.querySelector("form");
    const select = root.querySelector('select[name="levelOfDescription"]');
    const status = document.getElementById("ah-profile-status");
    const area = root.querySelector("[data-ah-reveal-area]");
    const fields = Array.from(root.querySelectorAll(".ah-switch[data-ah-field]"));
    const parse = (value) => {
      try {
        return JSON.parse(value || "{}");
      } catch (e) {
        return {};
      }
    };
    const profiles = parse(root.dataset.ahLevelProfiles);
    const titles = parse(root.dataset.ahProfileTitles);
    const announce = (text) => {
      if (status) {
        status.textContent = text;
      }
    };
    const isHidden = (el) => window.getComputedStyle(el).display === "none";
    const fieldByKey = (key) => fields.find((f) => f.dataset.ahField === key);

    // Solo se ofrece revelar los campos que ahora mismo están ocultos.
    const syncReveal = () => {
      if (!area) {
        return;
      }
      let any = false;
      area.querySelectorAll("[data-ah-reveal-item]").forEach((item) => {
        const field = fieldByKey(item.dataset.ahRevealItem);
        item.hidden = !field || !isHidden(field);
        any = any || !item.hidden;
      });
      area.hidden = !any;
    };

    const setLabel = (el, text) => {
      const target = el.matches("span, h3") ? el : el.querySelector("label");
      if (!target) {
        return;
      }
      const node = Array.from(target.childNodes).find((n) => n.nodeType === 3 && n.nodeValue.trim() !== "");
      if (node) {
        node.nodeValue = text;
      } else if (!target.children.length) {
        target.textContent = text;
      }
    };

    const currentProfile = () => (select && profiles[select.value]) || "general";
    const applyProfile = (profile, speak) => {
      root.dataset.ahProfile = profile;
      root.querySelectorAll("[data-ah-labels]").forEach((el) => {
        const labels = parse(el.dataset.ahLabels);
        const text = labels[profile] || labels.general;
        setLabel(el, text);
        // El botón de ayuda anuncia el nombre del campo ("Más información: <etiqueta>"): se mantiene en sincronía.
        const help = (el.matches("span") ? el.parentElement : el).querySelector(".uc-help-toggle");
        const current = help && help.getAttribute("aria-label");
        if (current && current.includes(": ")) {
          help.setAttribute("aria-label", current.slice(0, current.lastIndexOf(": ") + 2) + text);
        }
      });
      const name = root.querySelector("[data-ah-profile-name]");
      if (name) {
        name.textContent = titles[profile] ? `— ${titles[profile]}` : "";
      }
      syncReveal();
      if (speak && titles[profile]) {
        announce(`Ficha: ${titles[profile]}.`);
      }
    };
    // Primer control editable de un campo conmutable (el que crea el autocomplete, o el nativo).
    const focusField = (field) => {
      const control = field && field.querySelector("input:not([type=hidden]), textarea, select");
      if (control) {
        control.focus();
      }
    };
    const start = (profile, focusKey) => {
      root.dataset.ahState = "ready";
      applyProfile(profile, true);
      if (focusKey) {
        focusField(fieldByKey(focusKey));
      }
    };

    if (select) {
      select.addEventListener("change", () => {
        const profile = currentProfile();
        if (root.dataset.ahState === "initial") {
          // Elegir un nivel muestra la ficha; volver a "sin nivel" después de empezar nunca la oculta de nuevo.
          start(profile);
        } else {
          applyProfile(profile, true);
        }
      });
    }
    const noLevel = root.querySelector("[data-ah-no-level]");
    if (noLevel) {
      noLevel.addEventListener("click", () => start("general", "identifier"));
    }

    // Un campo que el catalogador ya tocó (o reveló) sigue visible aunque cambie el perfil.
    const markUsed = (event) => {
      const field = event.target.closest && event.target.closest(".ah-switch");
      if (field) {
        field.classList.add("ah-used");
      }
    };
    root.addEventListener("focusin", markUsed);
    root.addEventListener("input", markUsed);
    root.addEventListener("change", markUsed);

    root.addEventListener("click", (event) => {
      const button = event.target.closest("[data-ah-reveal]");
      if (!button) {
        return;
      }
      const field = fieldByKey(button.dataset.ahReveal);
      if (field) {
        field.classList.add("ah-used");
        syncReveal();
        focusField(field);
        const label = field.querySelector("label");
        announce(label ? `Campo ${label.textContent.trim()} mostrado.` : "Campo mostrado.");
      }
    });

    // En el estado inicial no se envía nada: ni Enter ni un submit programático hasta elegir nivel o "Describir sin nivel".
    if (form) {
      form.addEventListener("submit", (event) => {
        if (root.dataset.ahState === "initial") {
          event.preventDefault();
        }
      });
    }

    // Errores de validación: abrir las áreas adicionales que los contienen.
    const additionalPanel = document.getElementById("ah-additional-fields");
    root.querySelectorAll(".accordion-collapse").forEach((panel) => {
      if (panel.querySelector(".is-invalid, .invalid-feedback")) {
        bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false }).show();
        if (additionalPanel) {
          bootstrap.Collapse.getOrCreateInstance(additionalPanel, { toggle: false }).show();
        }
      }
    });

    // La adaptación solo rige desde aquí: sin este script (JavaScript desactivado, bundle caído o aún cargando) la raíz no
    // lleva `.ah-js` y la página es el formulario general completo, sin estado inicial ni ocultación.
    // Si el bundle llega tarde y el catalogador ya empezó a escribir en ese formulario completo, no se le esconde la ficha:
    // se pasa directamente al perfil de su nivel.
    const touched = () =>
      Array.from(form ? form.elements : []).some((el) => {
        if (el.type === "hidden" || el.type === "submit" || el.type === "button") {
          return false;
        }
        if (el.tagName === "SELECT") {
          // Un select simple sin `selected` muestra su primera opción: eso no es una edición del usuario.
          const base = el.multiple ? -2 : Math.max(0, Array.from(el.options).findIndex((o) => o.defaultSelected));
          return Array.from(el.options).some((o, i) => o.selected !== (el.multiple ? o.defaultSelected : i === base));
        }
        return el.type === "checkbox" || el.type === "radio" ? el.checked !== el.defaultChecked : el.value !== el.defaultValue;
      });
    if (root.dataset.ahState === "initial" && (root.contains(document.activeElement) || touched())) {
      root.dataset.ahState = "ready";
      root.dataset.ahProfile = currentProfile();
    }
    root.classList.add("ah-js");
    applyProfile(root.dataset.ahState === "initial" ? "general" : root.dataset.ahProfile || "general", false);

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
