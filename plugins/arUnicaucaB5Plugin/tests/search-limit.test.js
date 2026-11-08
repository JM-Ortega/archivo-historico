// Prueba de js/search-limit.js (KAN-140) con un DOM mínimo simulado; no necesita navegador. Se ejecuta con tests/test-search-limit.sh.
const fs = require("fs");
const path = require("path");
const assert = require("assert");

class FakeInput {
  constructor(name, id) {
    this.name = name; this.id = id; this.value = ""; this.attrs = {}; this.dataset = {}; this.after = null;
  }
  setAttribute(k, v) { this.attrs[k] = v; }
  removeAttribute(k) { delete this.attrs[k]; }
  insertAdjacentElement(_, el) { this.after = el; document.byId[el.id] = el; return el; }
}
const listeners = {};
global.HTMLInputElement = FakeInput;
global.document = {
  byId: {},
  getElementById(id) { return this.byId[id] || null; },
  createElement() { return { id: "", className: "", hidden: false, textContent: "", setAttribute(k, v) { this[k] = v; } }; },
  addEventListener(type, fn) { listeners[type] = fn; },
};

eval(fs.readFileSync(path.join(__dirname, "../js/search-limit.js"), "utf8"));
const type = (input, text) => { input.value = text; listeners.input({ target: input }); };

const basic = new FakeInput("query", "search-box-input");
type(basic, "a".repeat(250));
assert.strictEqual(basic.after, null, "250 exactos no avisan");
type(basic, "a".repeat(251));
assert.ok(basic.after && !basic.after.hidden, "251 avisan");
assert.strictEqual(basic.after.textContent, "Se excedió el máximo de caracteres permitidos.");
assert.strictEqual(basic.attrs["aria-invalid"], "true");
assert.strictEqual(basic.dataset.ucOverLimit, "1");
type(basic, "a".repeat(10));
assert.ok(basic.after.hidden, "al bajar del máximo el aviso se oculta");
assert.strictEqual(basic.attrs["aria-invalid"], undefined);

const adv = new FakeInput("sq3", "");
type(adv, "á".repeat(200));
assert.strictEqual(adv.after, null, "criterio: 200 no avisa");
type(adv, "á".repeat(201));
assert.ok(adv.after && !adv.after.hidden, "criterio: 201 avisa");

const emoji = new FakeInput("query", "q2");
type(emoji, "😀".repeat(250)); // 250 puntos de código = 500 unidades UTF-16: debe contar 250
assert.strictEqual(emoji.after, null, "cuenta caracteres, no unidades UTF-16");

const other = new FakeInput("sf0", "x");
type(other, "z".repeat(999));
assert.strictEqual(other.after, null, "otros campos no se tocan");
console.log("ok: search-limit.js (6 casos)");
