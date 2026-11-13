// Cascada "Pertenece a" del alta de una descripción (sfIsadPlugin/editSuccess.php del plugin): Fondo → Sección → Serie →
// Subserie. Cada paso es opcional y abre el siguiente con las descripciones que hay dentro del elegido. La descripción nueva
// queda dentro del último elegido o, si no se elige nada, en el nivel superior.
//
// No añade nada al POST: los selects no tienen `name` y lo único que cambia es el valor del hidden nativo `parent`, que AtoM
// ya usa en el alta (el servidor lo valida en arUnicaucaCatalogingParents::chosen). Sin JavaScript el bloque sigue oculto.
(() => {
  "use strict";

  const init = () => {
    const box = document.querySelector("[data-ah-parent-cascade]");
    const form = box && box.closest("form");
    const hidden = form && form.querySelector('input[type="hidden"][name="parent"]');
    if (!box || !hidden) {
      return;
    }

    let data;
    try {
      data = JSON.parse(box.dataset.ahParentCascade);
    } catch (e) {
      return;
    }
    const texts = data.texts || {};
    const items = data.items || [];
    const byId = new Map(items.map((item) => [item.id, item]));
    const slug = (value) =>
      decodeURIComponent(
        String(value || "")
          .split(/[?#]/)[0]
          .replace(/\/+$/, "")
          .split("/")
          .pop(),
      );

    // Padre con el que llega el formulario (?parent=, o lo enviado si vuelve por un error de validación).
    const initialValue = hidden.value;
    const isRoot = initialValue === "" || initialValue === data.root || slug(initialValue) === slug(data.root);
    const initial = isRoot ? null : items.find((item) => slug(item.url) === slug(initialValue));
    // Si el padre no es Fondo, Sección, Serie ni Subserie (p. ej. una unidad documental), la cascada no puede
    // representarlo: se deja la ubicación tal como viene del servidor.
    if (!isRoot && !initial) {
      return;
    }

    const chainOf = (item) => {
      const chain = [];
      for (let cur = item; cur; cur = byId.get(cur.parent)) {
        chain.unshift(cur);
      }
      return chain;
    };

    // Opciones de cada paso: el primero, los fondos; los siguientes, las descripciones directamente dentro del elegido.
    const optionsFor = (parent) =>
      parent
        ? items.filter((item) => item.parent === parent.id && item.level > 0)
        : items.filter((item) => item.level === 0);

    // Etiqueta del paso según los niveles que ofrece: "Sección", "Serie o Subserie", "Sección, Serie o Subserie".
    const labelFor = (options) => {
      const names = [...new Set(options.map((o) => o.level))].sort().map((level) => data.levels[level]);
      return names.length > 1 ? `${names.slice(0, -1).join(", ")} ${texts.or} ${names[names.length - 1]}` : names[0];
    };

    const steps = box.querySelector("[data-ah-parent-steps]");
    const path = document.querySelector(".ah-location-path");
    let counter = 0;

    const renderPath = (chain) => {
      if (!path) {
        return;
      }
      const last = path.querySelector(".ah-location-new");
      path.textContent = "";
      if (!chain.length) {
        const li = document.createElement("li");
        li.textContent = texts.topLevel;
        path.append(li);
      }
      chain.forEach((item) => {
        const li = document.createElement("li");
        li.textContent = `${item.label} `;
        const level = document.createElement("span");
        level.className = "ah-location-level";
        level.textContent = `(${data.levels[item.level]})`;
        li.append(level);
        path.append(li);
      });
      if (last) {
        path.append(last);
      }
    };

    const apply = (chain) => {
      const tip = chain[chain.length - 1];
      hidden.value = tip ? tip.url : data.root;
      renderPath(chain);
    };

    // Dibuja los pasos desde `fromIndex` según la cadena elegida. `initialLoad` marca la selección como la "de partida"
    // (defaultSelected) para que no cuente como un cambio del usuario en cataloging-form.js.
    const render = (chain, initialLoad) => {
      steps.textContent = "";
      let parent = null;
      for (let index = 0; index <= chain.length; index += 1) {
        const options = optionsFor(parent);
        if (!options.length) {
          break;
        }
        const id = `ah-parent-step-${(counter += 1)}`;
        const col = document.createElement("div");
        col.className = "col-md-6 col-lg-3 ah-parent-step";
        const label = document.createElement("label");
        label.className = "form-label";
        label.htmlFor = id;
        label.textContent = labelFor(options);
        const select = document.createElement("select");
        select.id = id;
        select.className = "form-select";
        select.setAttribute("aria-describedby", "ah-parent-hint");
        select.dataset.ahStep = String(index);
        const chosen = chain[index];
        select.append(new Option(index === 0 ? texts.noFonds : texts.none, "", !chosen && initialLoad, !chosen));
        options.forEach((item) => {
          const selected = !!chosen && chosen.id === item.id;
          select.append(new Option(item.label, String(item.id), selected && initialLoad, selected));
        });
        col.append(label, select);
        steps.append(col);
        if (!chosen) {
          break;
        }
        parent = chosen;
      }
    };

    let chain = initial ? chainOf(initial) : [];
    // La cadena de partida tiene que poder recorrerse paso a paso (fondo y luego descripciones directamente dentro). Si no
    // (p. ej. una sección sin fondo encima), la cascada no la representa y la ubicación queda como viene del servidor.
    const walkable = chain.every((item, index) => optionsFor(chain[index - 1] || null).includes(item));
    if (!walkable || !optionsFor(null).length) {
      return;
    }
    steps.addEventListener("change", (event) => {
      const select = event.target.closest("select[data-ah-step]");
      if (!select) {
        return;
      }
      const index = Number(select.dataset.ahStep);
      const item = byId.get(Number(select.value));
      chain = chain.slice(0, index).concat(item ? [item] : []);
      render(chain, false);
      apply(chain);
      // El foco vuelve al paso que se cambió (render lo recrea).
      const again = steps.querySelector(`select[data-ah-step="${index}"]`);
      if (again) {
        again.focus();
      }
    });

    // La ubicación de partida ya la pinta el servidor; se redibuja solo cuando el catalogador cambia la cascada.
    render(chain, true);
    box.hidden = false;
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
