// Navegación temática «Lugares» (modules/taxonomy/templates/indexSuccess.php): al cambiar «Ordenar por» o «Dirección» se
// envía el formulario, como en el prototipo. Sin JS sigue funcionando: el formulario se envía con Intro en la búsqueda.
const initializePlacesFilters = () => {
  document.querySelectorAll("[data-ah-places-filters]").forEach((form) => {
    form.querySelectorAll("[data-ah-autosubmit]").forEach((select) => {
      select.addEventListener("change", () => form.requestSubmit());
    });
  });
};

if ("loading" === document.readyState) {
  document.addEventListener("DOMContentLoaded", initializePlacesFilters);
} else {
  initializePlacesFilters();
}
