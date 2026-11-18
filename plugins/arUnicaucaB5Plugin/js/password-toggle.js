// Botón «ojo» para mostrar u ocultar la contraseña escrita (componente por atributo).
//
// - Se aplica a los campos <input type="password" data-ah-password-toggle="true">; cada vista lo activa a propósito.
// - Envuelve el campo en .ah-password-field y añade el botón .ah-password-toggle a su derecha.
// - Al enviar el formulario el campo vuelve a ser de tipo contraseña, para que el navegador no la guarde como texto.
// - No cambia el tipo del campo al cargar: la fortaleza de contraseña de AtoM (js/user.js) sigue encontrándolo.
(() => {
  "use strict";

  const SHOW = "Mostrar contraseña";
  const HIDE = "Ocultar contraseña";

  const setVisible = (input, button, visible) => {
    input.type = visible ? "text" : "password";
    button.setAttribute("aria-pressed", visible ? "true" : "false");
    button.setAttribute("aria-label", visible ? HIDE : SHOW);
    button.title = visible ? HIDE : SHOW;
    const icon = button.querySelector("i");
    icon.classList.toggle("fa-eye", !visible);
    icon.classList.toggle("fa-eye-slash", visible);
  };

  const enhance = (input) => {
    if (input.closest(".ah-password-field")) {
      return;
    }

    const wrapper = document.createElement("div");
    wrapper.className = "ah-password-field";
    // Con error del servidor, el mensaje (.invalid-feedback) es hermano del envoltorio y no del campo:
    // la clase en el envoltorio mantiene visible el mensaje de Bootstrap.
    if (input.classList.contains("is-invalid")) {
      wrapper.classList.add("is-invalid");
    }
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const button = document.createElement("button");
    button.type = "button";
    button.className = "ah-password-toggle";
    if (input.id) {
      button.setAttribute("aria-controls", input.id);
    }
    const icon = document.createElement("i");
    icon.className = "far fa-eye";
    icon.setAttribute("aria-hidden", "true");
    button.appendChild(icon);
    wrapper.appendChild(button);

    setVisible(input, button, false);

    button.addEventListener("click", () => {
      setVisible(input, button, "password" === input.type);
      input.focus();
    });

    if (input.form) {
      input.form.addEventListener("submit", () => setVisible(input, button, false));
    }
  };

  const init = () => {
    document
      .querySelectorAll('input[type="password"][data-ah-password-toggle]')
      .forEach(enhance);
  };

  if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
