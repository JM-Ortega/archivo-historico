// Aviso de «máximo de caracteres» en la búsqueda (KAN-140): caja básica (`query`) y criterios de la avanzada (`sqN`).
//
// - No bloquea al escribir ni al pegar: avisa mientras el texto pasa del máximo; el servidor recorta (arUnicaucaSearchInput).
// - Se engancha por nombre de campo con un solo listener en `document`, así cubre la caja del encabezado, la de la portada
//   y los criterios que la avanzada añade con «Agregar nuevo», sin tocar las plantillas de AtoM ni de otros.
// - Cuenta caracteres (puntos de código), igual que mb_strlen en el servidor. Los límites deben coincidir con
//   arUnicaucaSearchInput::MAX_QUERY_LENGTH y ::MAX_TERM_LENGTH (tests/test-search-limit.sh lo comprueba).
(() => {
  "use strict";

  const LIMITS = [
    { matches: (name) => "query" === name, max: 250 },
    { matches: (name) => /^sq\d+$/.test(name), max: 200 },
  ];
  const MESSAGE = "Se excedió el máximo de caracteres permitidos.";
  const WARNING_CLASS = "ah-limit-warning";
  const FLAG = "uc-over-limit";

  const limitFor = (input) => {
    if (!(input instanceof HTMLInputElement) || !input.name) {
      return null;
    }
    const rule = LIMITS.find((candidate) => candidate.matches(input.name));
    return rule ? rule.max : null;
  };

  const warningFor = (input, create) => {
    const id = `${input.id || input.name}-limit-warning`;
    let warning = document.getElementById(id);
    if (!warning && create) {
      warning = document.createElement("div");
      warning.id = id;
      warning.className = WARNING_CLASS;
      warning.setAttribute("role", "status"); // Se anuncia a lectores de pantalla sin quitar el foco del campo.
      warning.textContent = MESSAGE;
      input.insertAdjacentElement("afterend", warning);
    }
    return warning;
  };

  const check = (input) => {
    const max = limitFor(input);
    if (null === max) {
      return;
    }
    const over = Array.from(input.value).length > max;
    const warning = warningFor(input, over);
    if (over) {
      input.setAttribute("aria-invalid", "true");
      input.setAttribute("aria-describedby", warning.id);
      input.dataset[FLAG.replace(/-./g, (m) => m[1].toUpperCase())] = "1";
      warning.hidden = false;
    } else if (warning) {
      input.removeAttribute("aria-invalid");
      input.removeAttribute("aria-describedby");
      delete input.dataset[FLAG.replace(/-./g, (m) => m[1].toUpperCase())];
      warning.hidden = true;
    }
  };

  document.addEventListener("input", (event) => check(event.target));
})();
