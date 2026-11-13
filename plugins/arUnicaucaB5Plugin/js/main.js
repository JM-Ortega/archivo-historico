import "../../arDominionB5Plugin/js/main";
import "./help";
import "./cataloging-form";
import "./places";
import "./description-updates";
import "./search-limit";
import "./user-email";

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

const initializeDescriptionAreas = () => {
  document.querySelectorAll("[data-ah-area-toggle]").forEach((toggle) => {
    const area = document.getElementById(toggle.dataset.ahAreaToggle);
    if (!area) {
      return;
    }

    const setExpanded = (expanded) => {
      Array.from(area.children)
        .filter((child) => !child.classList.contains("atom-section-header"))
        .forEach((child) => {
          child.hidden = !expanded;
        });
      toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
      toggle.classList.toggle("ah-area-expanded", expanded);
      toggle.parentElement.classList.toggle("ah-area-expanded", expanded);
      toggle.title = expanded
        ? toggle.dataset.ahCollapseTitle
        : toggle.dataset.ahExpandTitle;
    };

    toggle.dataset.ahCollapseTitle = toggle.title.replace(/^Editar\b/, "Ocultar");
    toggle.dataset.ahExpandTitle = toggle.title.replace(/^Editar\b/, "Mostrar");
    toggle.setAttribute("role", "button");
    toggle.setAttribute("aria-controls", area.id);
    toggle.setAttribute("aria-expanded", "true");
    toggle.addEventListener("click", (event) => {
      event.preventDefault();
      setExpanded("true" !== toggle.getAttribute("aria-expanded"));
    });
    setExpanded("true" === toggle.getAttribute("aria-expanded"));
  });
};

if ("loading" === document.readyState) {
  document.addEventListener("DOMContentLoaded", () => {
    initializeLoginControls();
    initializeDescriptionAreas();
  });
} else {
  initializeLoginControls();
  initializeDescriptionAreas();
}

/*

Extra/custom JS:

jQuery, $ and bootstrap are exposed globally. Also Drupal and Qubit,
but avoid its usage. Allows IIFEs, CommonJS, ES6 Modules and it could
allow Typescript if it's implemented in the Webpack build process.

*/
