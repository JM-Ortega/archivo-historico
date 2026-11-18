// Bloque "Pertenece a" del alta de una descripción (sfIsadPlugin/editSuccess.php del plugin): cuatro campos fijos, en orden
// estricto, Fondo → Sección → Serie → Subserie, todos con "Ninguno/Ninguna" por defecto.
//   - Cada campo solo ofrece descripciones de su nivel, y solo las que están dentro del campo elegido más arriba (a cualquier
//     profundidad: se pueden saltar niveles, p. ej. Fondo → Serie sin Sección).
//   - Al elegir una descripción, los campos de arriba se llenan solos con aquello a lo que pertenece (su fondo, su
//     sección...), y los de abajo que ya no estén dentro de ella vuelven a "Ninguno/Ninguna".
//   - Al dejar un campo en "Ninguno/Ninguna", los de abajo también se vacían.
//   - La descripción nueva queda dentro del último campo elegido o, si no se elige nada, en el nivel superior.
//
// No añade nada al POST: los selects no tienen `name` y lo único que cambia es el valor del hidden nativo `parent`, que AtoM
// ya usa en el alta (el servidor lo valida en arUnicaucaCatalogingContext::chosenParent). Sin JavaScript el bloque sigue
// oculto.
(() => {
  "use strict";

  const LEVELS = 4; // 0 Fondo, 1 Sección, 2 Serie, 3 Subserie

  const init = () => {
    const box = document.querySelector("[data-ah-parent-cascade]");
    const form = box && box.closest("form");
    const hidden = form && form.querySelector('input[type="hidden"][name="parent"]');
    const steps = box && box.querySelector("[data-ah-parent-steps]");
    if (!box || !hidden || !steps) {
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
    if (!items.length) {
      return;
    }
    const byId = new Map(items.map((item) => [item.id, item]));
    const slug = (value) =>
      decodeURIComponent(
        String(value || "")
          .split(/[?#]/)[0]
          .replace(/\/+$/, "")
          .split("/")
          .pop(),
      );

    // Ancestros conocidos de una descripción (los que son Fondo, Sección, Serie o Subserie), del más cercano al más lejano.
    const ancestorsOf = (item) => {
      const list = [];
      const seen = new Set([item.id]);
      for (let cur = byId.get(item.parent); cur && !seen.has(cur.id); cur = byId.get(cur.parent)) {
        seen.add(cur.id);
        list.push(cur);
      }
      return list;
    };
    const isInside = (item, container) => ancestorsOf(item).some((a) => a.id === container.id);

    // Elección actual: posición del campo → descripción (o null).
    const chosen = new Array(LEVELS).fill(null);
    const deepest = () => {
      for (let level = LEVELS - 1; level >= 0; level -= 1) {
        if (chosen[level]) {
          return chosen[level];
        }
      }
      return null;
    };

    // Opciones de un campo: descripciones de su nivel dentro del campo elegido más cercano por encima (o todas, si no hay).
    const optionsFor = (level) => {
      let container = null;
      for (let up = level - 1; up >= 0 && !container; up -= 1) {
        container = chosen[up];
      }
      return items.filter((item) => item.level === level && (!container || isInside(item, container)));
    };

    // Elegir `item` en su campo: llena hacia arriba con sus ancestros y vacía lo de abajo que ya no esté dentro de él.
    const choose = (level, item) => {
      if (!item) {
        for (let l = level; l < LEVELS; l += 1) {
          chosen[l] = null;
        }
        return;
      }
      chosen[level] = item;
      const ancestors = ancestorsOf(item);
      for (let up = 0; up < level; up += 1) {
        chosen[up] = ancestors.find((a) => a.level === up) || null;
      }
      for (let down = level + 1; down < LEVELS; down += 1) {
        if (chosen[down] && !isInside(chosen[down], item)) {
          chosen[down] = null;
        }
      }
    };

    // Padre con el que llega el formulario (?parent=, o lo enviado si vuelve por un error de validación).
    const initialValue = hidden.value;
    const isRoot = initialValue === "" || initialValue === data.root || slug(initialValue) === slug(data.root);
    const initial = isRoot ? null : items.find((item) => slug(item.url) === slug(initialValue));
    // Si el padre no es Fondo, Sección, Serie ni Subserie (p. ej. una unidad documental), estos campos no pueden
    // representarlo: se deja la ubicación tal como viene del servidor.
    if (!isRoot && !initial) {
      return;
    }
    if (initial) {
      choose(initial.level, initial);
    }

    // Los cuatro campos, creados una sola vez.
    const selects = [];
    for (let level = 0; level < LEVELS; level += 1) {
      const id = `ah-parent-level-${level}`;
      const col = document.createElement("div");
      col.className = "col-md-6 col-lg-3 ah-parent-step";
      const label = document.createElement("label");
      label.className = "form-label";
      label.htmlFor = id;
      label.textContent = data.levels[level] || "";
      const select = document.createElement("select");
      select.id = id;
      select.className = "form-select";
      select.setAttribute("aria-describedby", "ah-parent-hint");
      select.dataset.ahLevel = String(level);
      col.append(label, select);
      steps.append(col);
      selects.push(select);
    }

    // Rellena las opciones de los cuatro campos según la elección actual. `initialLoad` marca la selección como la "de
    // partida" (defaultSelected) para que no cuente como un cambio del usuario en cataloging-form.js.
    const fill = (initialLoad) => {
      selects.forEach((select, level) => {
        const current = chosen[level];
        const options = optionsFor(level);
        // Una elección hecha por el llenado hacia arriba siempre está en su lista; por si los datos fueran inconsistentes,
        // se añade igualmente para no perderla.
        if (current && !options.includes(current)) {
          options.unshift(current);
        }
        select.textContent = "";
        const none = level === 0 ? texts.noFonds : texts.none;
        select.append(new Option(none, "", !current && initialLoad, !current));
        options.forEach((item) => {
          const selected = !!current && current.id === item.id;
          select.append(new Option(item.label, String(item.id), selected && initialLoad, selected));
        });
        // Sin nada que elegir, el campo queda deshabilitado (se ve "Ninguno/Ninguna").
        select.disabled = options.length === 0;
      });
    };

    const path = document.querySelector(".ah-location-path");
    const renderPath = () => {
      if (!path) {
        return;
      }
      const tip = deepest();
      const chain = tip ? [...ancestorsOf(tip)].reverse().concat(tip) : [];
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

    steps.addEventListener("change", (event) => {
      const select = event.target.closest("select[data-ah-level]");
      if (!select) {
        return;
      }
      const level = Number(select.dataset.ahLevel);
      choose(level, byId.get(Number(select.value)) || null);
      fill(false);
      const tip = deepest();
      hidden.value = tip ? tip.url : data.root;
      renderPath();
    });

    // La ubicación de partida ya la pinta el servidor; se redibuja solo cuando el catalogador cambia algo.
    fill(true);
    box.hidden = false;
  };

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
