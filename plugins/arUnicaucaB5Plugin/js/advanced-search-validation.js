// Búsqueda avanzada (informationobject/browse): avisos claros antes de enviar (KAN-163; HE1-HU-002, escenario 2).
//
// - Sin ningún criterio ni filtro, el envío se detiene con «Escribe al menos un criterio de búsqueda o elige un filtro.»
//   (antes la aplicación listaba todo el catálogo sin avisar). Un filtro (nivel, fechas, etc.) cuenta como criterio.
// - «Fecha final» anterior a «Fecha inicial»: se detiene con un aviso sobre el campo final (antes daba resultados raros en
//   silencio).
// Misma técnica que el Historial (js/description-updates.js): el aviso nativo del navegador sobre el campo (setCustomValidity),
// que bloquea el envío, es accesible y se quita solo al corregir. El servidor no cambia: una URL armada a mano sigue funcionando.
// La decisión del mensaje es una función pura (evaluar) para probarla sin navegador (tests/advanced-validation.test.js).

const NO_CRITERIA_MESSAGE =
  "Escribe al menos un criterio de búsqueda o elige un filtro.";
const END_BEFORE_START_MESSAGE =
  "La fecha final no puede ser anterior a la fecha inicial.";

// criterios: textos de los campos sqN. filtros: valores de los demás campos (selects, textos y casillas marcadas).
// inicio y fin: AAAA-MM-DD (se comparan como texto, que equivale a compararlos como fechas).
const evaluar = ({ criterios = [], filtros = [], inicio = "", fin = "" }) => {
  const hayCriterio = criterios.some((valor) => "" !== String(valor).trim());
  const hayFiltro =
    filtros.some((valor) => "" !== String(valor).trim()) ||
    "" !== inicio ||
    "" !== fin;

  return {
    criterios: hayCriterio || hayFiltro ? "" : NO_CRITERIA_MESSAGE,
    fin: inicio && fin && fin < inicio ? END_BEFORE_START_MESSAGE : "",
  };
};

const initializeAdvancedSearchValidation = () => {
  const form = document.querySelector('form[name="advanced-search-form"]');

  // El Historial tiene su propio formulario con ese nombre y su propia validación.
  if (!form || form.closest(".ah-updates-filters")) {
    return;
  }

  const start = form.querySelector('input[name="startDate"]');
  const end = form.querySelector('input[name="endDate"]');

  const read = () => {
    const controls = Array.from(form.elements);
    const isCriterion = (control) => /^sq\d+$/.test(control.name);
    const isFilter = (control) => {
      if (!control.name || isCriterion(control) || /^(so|sf)\d+$/.test(control.name)) {
        return false;
      }
      if ("SELECT" === control.tagName) {
        return true;
      }
      if ("INPUT" !== control.tagName) {
        return false;
      }
      // Botones, ocultos y radios (modo de rango, solo descripciones de nivel superior) no son filtros.
      return ["text", "search", "date", "number", "checkbox"].includes(control.type) && "startDate" !== control.name && "endDate" !== control.name;
    };

    return {
      criterios: controls.filter(isCriterion).map((control) => control.value),
      filtros: controls
        .filter(isFilter)
        .map((control) => ("checkbox" === control.type && !control.checked ? "" : control.value)),
      inicio: start ? start.value : "",
      fin: end ? end.value : "",
    };
  };

  const apply = () => {
    const result = evaluar(read());
    const first = Array.from(form.elements).find((control) => /^sq\d+$/.test(control.name));

    if (first) {
      first.setCustomValidity(result.criterios);
    }
    if (end) {
      end.setCustomValidity(result.fin);
    }
  };

  ["input", "change"].forEach((type) => form.addEventListener(type, apply));
  // Restablecer, «Añadir nuevo criterio» y quitar un criterio cambian los campos sin disparar «input».
  form.addEventListener("reset", () => setTimeout(apply, 0));
  form.addEventListener("click", () => setTimeout(apply, 0));
  // Volver con «Atrás» restaura los valores escritos.
  window.addEventListener("pageshow", apply);

  apply();
};

if ("undefined" !== typeof module && module.exports) {
  module.exports = { evaluar, NO_CRITERIA_MESSAGE, END_BEFORE_START_MESSAGE };
}

if ("undefined" !== typeof document) {
  if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", initializeAdvancedSearchValidation);
  } else {
    initializeAdvancedSearchValidation();
  }
}
