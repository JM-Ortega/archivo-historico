// Máximo de caracteres en la búsqueda (KAN-140): caja básica (`query`) y criterios de la avanzada (`sqN`).
//
// - Avisa con un mensaje corto («Máx. 250 caracteres · sobran 13») mientras el texto pasa del máximo.
// - Mientras algún campo del formulario pase del máximo, el botón de buscar queda bloqueado y el envío (también con
//   Enter) se detiene. Se libera solo cuando todos los campos cumplen. El servidor sigue recortando (arUnicaucaSearchInput).
// - Se refresca solo: al escribir, pegar o borrar, al restablecer el formulario, al agregar o quitar criterios de la
//   avanzada, al volver con «Atrás» y al cargar la página con un valor ya escrito.
// - Se engancha por nombre de campo con listeners en `document`, así cubre la caja del encabezado, la de la portada
//   y los criterios que la avanzada añade con «Agregar nuevo», sin tocar las plantillas de AtoM ni de otros.
// - Cuenta caracteres (puntos de código), igual que mb_strlen en el servidor. Los límites deben coincidir con
//   arUnicaucaSearchInput::MAX_QUERY_LENGTH y ::MAX_TERM_LENGTH (tests/test-search-limit.sh lo comprueba).
(() => {
  "use strict";

  const LIMITS = [
    { matches: (name) => "query" === name, max: 250 },
    { matches: (name) => /^sq\d+$/.test(name), max: 200 },
  ];
  const WARNING_CLASS = "ah-limit-warning";
  const FLAG = "ucOverLimit";
  const BLOCKED = "ucLimitBlocked";
  const BLOCKED_HINT = "Reduce el texto para poder buscar";
  const SUBMIT_CONTROLS = 'button[type="submit"], input[type="submit"], button:not([type])';

  const message = (max, excess) => `Máx. ${max} caracteres · ${1 === excess ? "sobra 1" : `sobran ${excess}`}`;

  const limitFor = (input) => {
    if (!(input instanceof HTMLInputElement) || !input.name) {
      return null;
    }
    const rule = LIMITS.find((candidate) => candidate.matches(input.name));
    return rule ? rule.max : null;
  };

  // En un .input-group (buscador de la jerarquía) el campo y su botón son hermanos: el aviso va después del grupo, no en medio.
  const anchorFor = (input) => {
    const parent = input.parentElement;
    return parent && parent.classList && parent.classList.contains("input-group") ? parent : input;
  };

  const warningFor = (input, create) => {
    const id = `${input.id || input.name}-limit-warning`;
    let warning = document.getElementById(id);
    if (!warning && create) {
      warning = document.createElement("div");
      warning.id = id;
      warning.className = WARNING_CLASS;
      warning.setAttribute("role", "status"); // Se anuncia a lectores de pantalla sin quitar el foco del campo.
      anchorFor(input).insertAdjacentElement("afterend", warning);
    }
    return warning;
  };

  // Revisa un campo: muestra u oculta su aviso. Devuelve true si pasa del máximo; null si no tiene límite.
  const check = (input) => {
    const max = limitFor(input);
    if (null === max) {
      return null;
    }
    const excess = Array.from(input.value).length - max;
    const over = excess > 0;
    const warning = warningFor(input, over);
    if (over) {
      warning.textContent = message(max, excess);
      warning.hidden = false;
      input.setAttribute("aria-invalid", "true");
      input.setAttribute("aria-describedby", warning.id);
      input.dataset[FLAG] = "1";
    } else {
      if (warning) {
        warning.hidden = true;
      }
      input.removeAttribute("aria-invalid");
      input.removeAttribute("aria-describedby");
      delete input.dataset[FLAG];
    }
    return over;
  };

  // Bloquea o libera los botones de enviar del formulario. Solo libera los que bloqueó este script.
  // Se usa aria-disabled y no la propiedad `disabled`: Firefox conserva el `disabled` puesto por script al recargar (F5), y
  // entonces este script ya no podría liberarlo aunque el texto fuera válido. El envío lo detiene el evento «submit».
  const toggleSubmit = (form, blocked) => {
    form.querySelectorAll(SUBMIT_CONTROLS).forEach((control) => {
      if (blocked && !control.disabled && !control.dataset[BLOCKED]) {
        control.setAttribute("aria-disabled", "true");
        control.dataset[BLOCKED] = "1";
        control.setAttribute("title", BLOCKED_HINT);
      } else if (!blocked && control.dataset[BLOCKED]) {
        control.removeAttribute("aria-disabled");
        delete control.dataset[BLOCKED];
        control.removeAttribute("title");
      }
    });
  };

  // Revisa todos los campos con límite de un formulario y ajusta sus botones. Devuelve true si alguno pasa del máximo.
  const refresh = (form) => {
    if (!form) {
      return false;
    }
    let over = false;
    form.querySelectorAll("input").forEach((input) => {
      const result = check(input);
      if (null !== result) {
        over = over || result;
      }
    });
    // Siempre: si el último campo excedido desaparece, hay que liberar el botón que este script había bloqueado.
    toggleSubmit(form, over);
    return over;
  };

  // «Agregar nuevo» de la avanzada clona la fila completa, aviso incluido: el campo nuevo (vacío) quedaría con una copia
  // visible del aviso del original, con el mismo id, que nada actualiza. Se descartan los avisos que no pertenecen al
  // campo que tienen delante (el aviso propio de cada campo se recrea al hacer falta).
  const dropStale = () => {
    document.querySelectorAll(`.${WARNING_CLASS}`).forEach((warning) => {
      const previous = warning.previousElementSibling;
      const isOwn = (field) => warning.id === `${field.id || field.name}-limit-warning`;
      let owned = false;
      if (previous instanceof HTMLInputElement) {
        owned = isOwn(previous);
      } else if (previous && previous.classList && previous.classList.contains("input-group")) {
        owned = Array.from(previous.querySelectorAll("input")).some(isOwn);
      }
      if (!owned) {
        warning.remove();
      }
    });
  };

  const scan = () => {
    dropStale();
    document.querySelectorAll("form").forEach(refresh);
  };

  const onField = (event) => {
    if (event.target.form) {
      refresh(event.target.form);
    } else {
      check(event.target);
    }
  };

  document.addEventListener("input", onField);
  document.addEventListener("change", onField);

  // El envío se detiene aunque el botón no se use (Enter en el campo, otro script).
  document.addEventListener(
    "submit",
    (event) => {
      if (refresh(event.target)) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    },
    true
  );

  // Cambios que no disparan «input»: restablecer, «Agregar nuevo», quitar un criterio, autocompletar con un clic.
  document.addEventListener("reset", () => setTimeout(scan, 0), true);
  document.addEventListener("click", () => setTimeout(scan, 0), true);
  window.addEventListener("pageshow", scan); // Volver con «Atrás» restaura los valores escritos.

  let pending = null;
  new MutationObserver(() => {
    clearTimeout(pending);
    pending = setTimeout(scan, 50);
  }).observe(document.body, { childList: true, subtree: true });

  scan();
})();
