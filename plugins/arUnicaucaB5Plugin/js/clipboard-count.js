// Corrige el contador del portapapeles (icono de la cabecera) para que coincida con lo que muestra su página.
//
// El portapapeles de AtoM vive en el navegador (localStorage «clipboard») y su contador suma todo lo guardado ahí:
// - Descripciones que ya no existen o no se pueden ver (borradas, base de datos reiniciada, borradores). La página del
//   portapapeles no las muestra, pero el icono sí las contaba.
// - Registros de autoridad, instituciones e ingresos: el archivo no los usa y sus páginas están bloqueadas
//   (OCULTO-008, OCULTO-014), pero si quedaron guardados de antes también se contaban.
//
// Al cargar cada página se quitan del navegador los registros de tipos que no se usan y se pregunta al servidor
// (clipboard/sync) cuáles de las descripciones guardadas existen; las demás se quitan y el contador se actualiza.
// Si el servidor no responde, no se borra ninguna descripción.
(() => {
  "use strict";

  const STORAGE_KEY = "clipboard";
  const UNUSED_TYPES = ["actor", "repository", "accession"];

  const readItems = () => {
    try {
      const items = JSON.parse(localStorage.getItem(STORAGE_KEY));
      return items && "object" === typeof items ? items : null;
    } catch (error) {
      return null;
    }
  };

  const dropUnusedTypes = (items) => {
    let changed = false;
    UNUSED_TYPES.forEach((type) => {
      if (Array.isArray(items[type]) && items[type].length) {
        items[type] = [];
        changed = true;
      }
    });
    return changed;
  };

  const save = (clipboard, items) => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    clipboard.items = items;
    clipboard.updateCounts();
  };

  const sync = () => {
    const clipboard = window.atomClipboard;
    const menu = document.getElementById("clipboard-menu");
    const items = readItems();
    if (!clipboard || !menu || !items) {
      return;
    }

    const changed = dropUnusedTypes(items);
    const slugs = Array.isArray(items.informationObject)
      ? items.informationObject
      : [];

    if (!slugs.length || !menu.dataset.syncUrl) {
      if (changed) {
        save(clipboard, items);
      }
      return;
    }

    const body = new URLSearchParams();
    slugs.forEach((slug) => body.append("slugs[]", slug));

    fetch(menu.dataset.syncUrl, {
      method: "POST",
      body,
      credentials: "same-origin",
      headers: { "X-Requested-With": "XMLHttpRequest" },
    })
      .then((response) => (response.ok ? response.json() : Promise.reject()))
      .then((data) => {
        if (!data || !Array.isArray(data.informationObject)) {
          return Promise.reject();
        }

        const existing = new Set(data.informationObject);
        const checked = new Set(slugs);

        // Se vuelve a leer por si se agregó algo mientras llegaba la respuesta: lo nuevo no se toca.
        const current = readItems() || items;
        dropUnusedTypes(current);
        const before = Array.isArray(current.informationObject)
          ? current.informationObject
          : [];
        current.informationObject = before.filter(
          (slug) => existing.has(slug) || !checked.has(slug),
        );

        if (changed || current.informationObject.length !== before.length) {
          save(clipboard, current);
        }
      })
      .catch(() => {
        if (changed) {
          save(clipboard, items);
        }
      });
  };

  // AtoM crea window.atomClipboard en el «ready» de jQuery (DOMContentLoaded); «load» llega siempre después.
  if ("complete" === document.readyState) {
    setTimeout(sync, 0);
  } else {
    window.addEventListener("load", () => setTimeout(sync, 0));
  }
})();
