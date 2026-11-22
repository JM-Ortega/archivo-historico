// Prueba de js/advanced-search-validation.js (KAN-163): la decisión de los avisos de la búsqueda avanzada (función pura).
// Se ejecuta con tests/test-advanced-validation.sh. El enlace con el formulario real se comprueba en el navegador.
const assert = require("assert");
const path = require("path");

const { evaluar, NO_CRITERIA_MESSAGE, END_BEFORE_START_MESSAGE } = require(path.join(__dirname, "../js/advanced-search-validation.js"));

// HE1-HU-002, escenario 2: sin ningún criterio, hay que informarlo.
assert.strictEqual(evaluar({}).criterios, NO_CRITERIA_MESSAGE, "sin nada: aviso");
assert.strictEqual(evaluar({ criterios: ["", "   "] }).criterios, NO_CRITERIA_MESSAGE, "criterios en blanco: aviso");
assert.strictEqual(evaluar({ criterios: ["", ""], filtros: ["", ""] }).criterios, NO_CRITERIA_MESSAGE, "criterios y filtros vacíos: aviso");

// Con un criterio no hay aviso, aunque no sea el primero.
assert.strictEqual(evaluar({ criterios: ["carta"] }).criterios, "", "un criterio: sin aviso");
assert.strictEqual(evaluar({ criterios: ["", "carta"] }).criterios, "", "el criterio puede ser el segundo");
assert.strictEqual(evaluar({ criterios: ["  9052  "] }).criterios, "", "criterio con espacios alrededor");

// Un filtro cuenta como criterio (se puede buscar solo por nivel o por fechas).
assert.strictEqual(evaluar({ criterios: [""], filtros: ["", "241"] }).criterios, "", "solo filtro de nivel: sin aviso");
assert.strictEqual(evaluar({ inicio: "1810-01-01" }).criterios, "", "solo fecha inicial: sin aviso");
assert.strictEqual(evaluar({ fin: "1820-12-31" }).criterios, "", "solo fecha final: sin aviso");

// Fechas: la final no puede ser anterior a la inicial.
assert.strictEqual(evaluar({ inicio: "1820-01-01", fin: "1810-01-01" }).fin, END_BEFORE_START_MESSAGE, "final anterior: aviso");
assert.strictEqual(evaluar({ inicio: "1810-01-01", fin: "1820-01-01" }).fin, "", "rango válido: sin aviso");
assert.strictEqual(evaluar({ inicio: "1810-05-05", fin: "1810-05-05" }).fin, "", "mismo día: sin aviso");
assert.strictEqual(evaluar({ inicio: "1810-01-01" }).fin, "", "solo inicial: sin aviso de fecha");
assert.strictEqual(evaluar({ fin: "1810-01-01" }).fin, "", "solo final: sin aviso de fecha");
assert.strictEqual(evaluar({ inicio: "1899-12-31", fin: "1900-01-01" }).fin, "", "cambio de siglo");

// Los dos avisos son independientes.
const ambos = evaluar({ criterios: [""], inicio: "1820-01-01", fin: "1810-01-01" });
assert.strictEqual(ambos.criterios, "", "las fechas cuentan como filtro: no hay aviso de criterios");
assert.strictEqual(ambos.fin, END_BEFORE_START_MESSAGE, "y sí el de fechas");

// Los mensajes son claros y en español.
assert.ok(/al menos un criterio/i.test(NO_CRITERIA_MESSAGE), "el mensaje dice «al menos un criterio»");
assert.ok(/fecha final.*anterior.*fecha inicial/i.test(END_BEFORE_START_MESSAGE), "el mensaje explica las fechas");

console.log("ok: advanced-search-validation.js (criterios vacíos, filtros y rango de fechas)");
