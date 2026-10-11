// Ayuda contextual (toggletip) del theme: [data-uc-help] con .uc-help-toggle y .uc-help-bubble (lib/helper/UcHelpHelper.php).
//
// - Ratón: se muestra al pasar sobre el icono o la burbuja; un clic la fija abierta (útil en pantallas táctiles).
// - Teclado: se muestra al enfocar el botón; Intro/Espacio la fija; Escape la cierra.
// - Se cierra al salir (si no está fijada), con Escape o con un clic fuera.
(() => {
  "use strict";

  const OPEN = "is-open";
  const PINNED = "is-pinned";

  const toggleOf = (help) => help.querySelector(".uc-help-toggle");

  // Mantiene la burbuja dentro del viewport: si desbordaría por la derecha, se desplaza a la izquierda lo necesario.
  function fit(help) {
    const bubble = help.querySelector(".uc-help-bubble");
    const margin = 8;
    bubble.style.left = "0px";
    const rect = bubble.getBoundingClientRect();
    const overflow = rect.right - (document.documentElement.clientWidth - margin);
    if (overflow > 0) {
      bubble.style.left = `${-Math.min(overflow, rect.left - margin)}px`;
    }
  }

  function setOpen(help, open) {
    help.classList.toggle(OPEN, open);
    if (!open) {
      help.classList.remove(PINNED);
    } else {
      fit(help);
    }
    toggleOf(help).setAttribute("aria-expanded", open ? "true" : "false");
  }

  function closeAll(except) {
    document.querySelectorAll(`[data-uc-help].${OPEN}`).forEach((help) => {
      if (help !== except) {
        setOpen(help, false);
      }
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("[data-uc-help]").forEach((help) => {
      const toggle = toggleOf(help);

      help.addEventListener("mouseenter", () => {
        closeAll(help);
        setOpen(help, true);
      });
      help.addEventListener("mouseleave", () => {
        if (!help.classList.contains(PINNED) && document.activeElement !== toggle) {
          setOpen(help, false);
        }
      });
      toggle.addEventListener("focus", () => {
        closeAll(help);
        setOpen(help, true);
      });
      toggle.addEventListener("blur", () => {
        if (!help.classList.contains(PINNED) && !help.matches(":hover")) {
          setOpen(help, false);
        }
      });
      toggle.addEventListener("click", () => {
        const pin = !help.classList.contains(PINNED);
        closeAll(help);
        setOpen(help, true);
        help.classList.toggle(PINNED, pin);
      });
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        closeAll();
      }
    });
    document.addEventListener("click", (event) => {
      document.querySelectorAll(`[data-uc-help].${PINNED}`).forEach((help) => {
        if (!help.contains(event.target)) {
          setOpen(help, false);
        }
      });
    });
  });
})();
