// Validación en vivo del correo en «Crear nuevo usuario» / «Editar usuario».
//
// - Marca el campo con un asterisco rojo (obligatorio).
// - Si el correo escrito no termina en @unicauca.edu.co, el campo y el aviso
//   «Debe ingresar un correo válido de la Unicauca, @unicauca.edu.co.» se ponen en rojo
//   y el formulario no se envía. Al corregirlo, vuelven a su color normal.
// - El patrón debe coincidir con arUnicaucaEmailDomainPolicy::PATTERN (validación del servidor).
(() => {
  "use strict";

  const PATTERN = /^[^@\s]+@unicauca\.edu\.co$/i;
  const ERROR_CLASS = "ah-user-hint--error";

  const init = () => {
    const form = document.getElementById("editForm");
    const hint = document.getElementById("ah-user-email-hint");
    const email = form ? form.querySelector('input[name="email"]') : null;
    if (!form || !hint || !email) {
      return;
    }

    const message = hint.textContent.trim();

    const label = email.id ? form.querySelector(`label[for="${email.id}"]`) : null;
    if (label && !label.querySelector(".ah-required-mark")) {
      const mark = document.createElement("span");
      mark.className = "ah-required-mark";
      mark.setAttribute("aria-hidden", "true");
      mark.textContent = " *";
      label.appendChild(mark);
    }
    email.required = true;

    const check = (showEmpty) => {
      const value = email.value.trim();
      const invalid = "" === value ? showEmpty : !PATTERN.test(value);
      email.classList.toggle("is-invalid", invalid);
      hint.classList.toggle(ERROR_CLASS, invalid);
      hint.setAttribute("role", invalid ? "alert" : "note");
      email.setAttribute("aria-invalid", invalid ? "true" : "false");
      email.setCustomValidity(invalid ? message : "");
      return !invalid;
    };

    email.addEventListener("input", () => check(false));
    email.addEventListener("blur", () => check(true));
    form.addEventListener("submit", (event) => {
      if (!check(true)) {
        event.preventDefault();
        email.focus();
      }
    });

    // Si el servidor devolvió el formulario con el correo rechazado, se muestra en rojo desde el inicio.
    if ("" !== email.value.trim() || email.classList.contains("is-invalid")) {
      check(email.classList.contains("is-invalid"));
    }
  };

  if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
