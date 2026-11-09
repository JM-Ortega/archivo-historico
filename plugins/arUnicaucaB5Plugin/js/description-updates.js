// Administrador → Historial (search/descriptionUpdates): el rango de fechas debe ser válido antes de buscar.
// «Fin» no puede ser anterior a «Inicio». El servidor valida lo mismo (validateDateRange en la acción del theme);
// esto solo evita enviar el formulario y muestra el aviso nativo del navegador sobre el campo «Fin».

const END_BEFORE_START_MESSAGE =
  "La fecha de fin no puede ser anterior a la fecha de inicio.";

const initializeDescriptionUpdatesDateRange = () => {
  const form = document.querySelector(
    '.ah-updates-filters form[name="advanced-search-form"]',
  );

  if (!form) {
    return;
  }

  const start = form.querySelector('input[name="startDate"]');
  const end = form.querySelector('input[name="endDate"]');

  if (!start || !end) {
    return;
  }

  const sync = () => {
    // El calendario de «Fin» no deja elegir días anteriores a «Inicio».
    end.min = start.value || "";

    // Los valores de <input type="date"> son AAAA-MM-DD: compararlos como texto equivale a compararlos como fechas.
    const isInvalid = start.value && end.value && end.value < start.value;
    end.setCustomValidity(isInvalid ? END_BEFORE_START_MESSAGE : "");
  };

  ["input", "change"].forEach((type) => {
    start.addEventListener(type, sync);
    end.addEventListener(type, sync);
  });

  sync();
};

if ("loading" === document.readyState) {
  document.addEventListener(
    "DOMContentLoaded",
    initializeDescriptionUpdatesDateRange,
  );
} else {
  initializeDescriptionUpdatesDateRange();
}
