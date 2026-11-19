// Prueba de js/search-limit.js (KAN-140) con un DOM mínimo simulado; no necesita navegador. Se ejecuta con tests/test-search-limit.sh.
const fs = require("fs");
const path = require("path");
const assert = require("assert");

class FakeEl {
  constructor() { this.attrs = {}; this.dataset = {}; this.disabled = false; }
  setAttribute(k, v) { this.attrs[k] = v; }
  removeAttribute(k) { delete this.attrs[k]; }
}
class FakeInput extends FakeEl {
  constructor(name, id, form, value = "") {
    super(); this.name = name; this.id = id; this.value = value; this.form = form || null; this.after = null;
    if (form) { form.inputs.push(this); }
  }
  insertAdjacentElement(_, el) { this.after = el; document.byId[el.id] = el; return el; }
}
class FakeForm {
  constructor() { this.inputs = []; this.buttons = []; }
  button(disabled = false) { const b = new FakeEl(); b.disabled = disabled; this.buttons.push(b); return b; }
  querySelectorAll(sel) { return "input" === sel ? this.inputs : this.buttons; }
}

const listeners = {};
const winListeners = {};
let observed = null;
const forms = [];
global.HTMLInputElement = FakeInput;
global.MutationObserver = class { constructor(cb) { observed = cb; } observe() {} };
global.window = { addEventListener(type, fn) { winListeners[type] = fn; } };
global.document = {
  byId: {},
  body: {},
  getElementById(id) { return this.byId[id] || null; },
  createElement() { return { id: "", className: "", hidden: false, textContent: "", attrs: {}, setAttribute(k, v) { this.attrs[k] = v; } }; },
  addEventListener(type, fn) { listeners[type] = fn; },
  querySelectorAll(sel) { return "form" === sel ? forms : []; },
};

// Estado previo a cargar el script: formulario básico y uno con un valor ya escrito (volver con «Atrás»).
const basicForm = new FakeForm(); forms.push(basicForm);
const basicBtn = basicForm.button();
const basic = new FakeInput("query", "search-box-input", basicForm);

const restoredForm = new FakeForm(); forms.push(restoredForm);
const restoredBtn = restoredForm.button();
new FakeInput("query", "restored", restoredForm, "a".repeat(251));

eval(fs.readFileSync(path.join(__dirname, "../js/search-limit.js"), "utf8"));

const type = (input, text) => { input.value = text; listeners.input({ target: input }); };
const submit = (form) => { let stopped = false; listeners.submit({ target: form, preventDefault() { stopped = true; }, stopImmediatePropagation() {} }); return stopped; };
const later = () => new Promise((resolve) => setTimeout(resolve, 80));

(async () => {
  // Al cargar con un valor ya excedido, el botón nace bloqueado.
  assert.ok(restoredBtn.disabled, "al cargar con texto excedido el botón queda bloqueado");

  // Caja básica: 250 exactos pasan; 251 avisan, bloquean y detienen el envío.
  type(basic, "a".repeat(250));
  assert.strictEqual(basic.after, null, "250 exactos no avisan");
  assert.ok(!basicBtn.disabled, "250 exactos dejan el botón activo");
  assert.strictEqual(submit(basicForm), false, "250 exactos se envían");

  type(basic, "a".repeat(251));
  assert.ok(basic.after && !basic.after.hidden, "251 avisan");
  assert.strictEqual(basic.after.textContent, "Máx. 250 caracteres · sobra 1");
  assert.ok(basicBtn.disabled, "251 bloquean el botón");
  assert.strictEqual(basicBtn.attrs.title, "Reduce el texto para poder buscar");
  assert.strictEqual(basic.attrs["aria-invalid"], "true");
  assert.strictEqual(basic.dataset.ucOverLimit, "1");
  assert.strictEqual(submit(basicForm), true, "el envío se detiene aunque no se use el botón");

  type(basic, "a".repeat(263));
  assert.strictEqual(basic.after.textContent, "Máx. 250 caracteres · sobran 13", "el aviso cuenta lo que sobra");

  // Refresco automático: al bajar del máximo todo se libera solo.
  type(basic, "a".repeat(10));
  assert.ok(basic.after.hidden, "al bajar del máximo el aviso se oculta");
  assert.ok(!basicBtn.disabled, "al bajar del máximo el botón se libera");
  assert.strictEqual(basicBtn.attrs.title, undefined);
  assert.strictEqual(basic.attrs["aria-invalid"], undefined);
  assert.strictEqual(submit(basicForm), false, "ya cumple: se envía");

  // Avanzada: varios criterios en un mismo formulario; basta uno excedido para bloquear.
  const advForm = new FakeForm(); forms.push(advForm);
  const advBtn = advForm.button();
  const stillDisabled = advForm.button(true); // un botón deshabilitado por otro motivo no se toca
  const sq0 = new FakeInput("sq0", "", advForm);
  const sq1 = new FakeInput("sq1", "", advForm);
  type(sq0, "á".repeat(200));
  assert.strictEqual(sq0.after, null, "criterio: 200 no avisa");
  assert.ok(!advBtn.disabled);
  type(sq0, "á".repeat(201));
  assert.ok(sq0.after && !sq0.after.hidden, "criterio: 201 avisa");
  assert.strictEqual(sq0.after.textContent, "Máx. 200 caracteres · sobra 1");
  assert.ok(advBtn.disabled, "criterio excedido bloquea buscar");
  type(sq1, "ok");
  assert.ok(advBtn.disabled, "otro criterio válido no libera mientras sq0 siga excedido");
  type(sq0, "corto");
  assert.ok(!advBtn.disabled, "al corregir el último excedido se libera");
  assert.ok(stillDisabled.disabled && !stillDisabled.dataset.ucLimitBlocked, "no se libera lo que bloqueó otro");

  // «Agregar nuevo» y quitar criterio (cambios en el DOM) refrescan solos.
  const sq2 = new FakeInput("sq2", "", advForm);
  type(sq2, "x".repeat(201));
  assert.ok(advBtn.disabled);
  advForm.inputs.splice(advForm.inputs.indexOf(sq2), 1); // el usuario quita el criterio
  observed();
  await later();
  assert.ok(!advBtn.disabled, "quitar el criterio excedido libera el botón");

  // Restablecer: el valor cambia sin evento «input»; se refresca solo.
  type(sq0, "z".repeat(201));
  assert.ok(advBtn.disabled);
  sq0.value = "";
  listeners.reset({});
  await later();
  assert.ok(!advBtn.disabled, "restablecer libera el botón");

  // Volver con «Atrás».
  type(sq0, "z".repeat(201));
  sq0.value = "z".repeat(5);
  winListeners.pageshow();
  assert.ok(!advBtn.disabled, "pageshow vuelve a revisar");

  // Cuenta caracteres, no unidades UTF-16.
  const emojiForm = new FakeForm(); forms.push(emojiForm);
  const emoji = new FakeInput("query", "q2", emojiForm);
  type(emoji, "😀".repeat(250)); // 250 puntos de código = 500 unidades UTF-16
  assert.strictEqual(emoji.after, null, "cuenta caracteres, no unidades UTF-16");

  // Otros campos no se tocan.
  const otherForm = new FakeForm(); forms.push(otherForm);
  const otherBtn = otherForm.button();
  const other = new FakeInput("sf0", "x", otherForm);
  type(other, "z".repeat(999));
  assert.strictEqual(other.after, null, "otros campos no se tocan");
  assert.ok(!otherBtn.disabled, "formularios sin campos con límite no se bloquean");

  console.log("ok: search-limit.js (aviso corto, bloqueo del botón y refresco automático)");
})().catch((error) => { console.error(error); process.exit(1); });
