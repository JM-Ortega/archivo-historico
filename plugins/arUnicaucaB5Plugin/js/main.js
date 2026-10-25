import "../../arDominionB5Plugin/js/main";
import "./help";
import "./cataloging-form";

const initializeLoginControls = () => {
  const forgot = document.querySelector("[data-ah-login-forgot]");
  const recovery = document.getElementById("ah-login-recovery");
  const loginForm = document.querySelector(".ah-login-form");
  const email = document.getElementById("email");

  if (forgot && recovery) {
    forgot.addEventListener("click", () => {
      const isExpanded = "true" === forgot.getAttribute("aria-expanded");
      recovery.hidden = isExpanded;
      forgot.setAttribute("aria-expanded", isExpanded ? "false" : "true");
    });
  }

  if (loginForm && email) {
    email.addEventListener("input", () => {
      email.setCustomValidity("");
    });

    email.addEventListener("invalid", () => {
      if (email.validity.patternMismatch) {
        email.setCustomValidity(
          "Debe usar un correo institucional terminado en @unicauca.edu.co.",
        );
      }
    });
  }
};

if ("loading" === document.readyState) {
  document.addEventListener("DOMContentLoaded", initializeLoginControls);
} else {
  initializeLoginControls();
}

/*

Extra/custom JS:

jQuery, $ and bootstrap are exposed globally. Also Drupal and Qubit,
but avoid its usage. Allows IIFEs, CommonJS, ES6 Modules and it could
allow Typescript if it's implemented in the Webpack build process.

*/
