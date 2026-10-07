/**
 * Contract test: Form Engine browser core (estandar-form-engine.js, archivo REAL).
 * Cubre el núcleo puro expuesto por el test hook: matchesWhen (todos los
 * operadores) · reduceConditions (efectos show/hide/clear/require/optional/
 * set_lead_quality/set_lead_reason, rama then/else) · stepVisibility
 * (gating visible_when.step_completed) · paridad con la semántica PHP.
 * Sin framework: node tests/run-form-engine-browser.test.js
 */
'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');

/* ── Sandbox mínimo de navegador ─────────────────────────────────────────── */
const noop = () => {};
const windowStub = {
    addEventListener: noop,
    setTimeout: setTimeout,
    scrollTo: noop,
    pageYOffset: 0,
    CSS: { escape: (v) => String(v) },
    __ESTANDAR_FORMS_ENGINE_TEST__: {}
};
const documentStub = {
    readyState: 'complete',
    addEventListener: noop,
    querySelector: () => null,
    querySelectorAll: () => [],
    createElement: () => ({ style: {}, setAttribute: noop, appendChild: noop, classList: { add: noop, toggle: noop, remove: noop } })
};

const sandbox = {
    window: windowStub,
    document: documentStub,
    WeakMap: WeakMap,
    Array: Array,
    Object: Object,
    JSON: JSON,
    String: String,
    console: console,
    setTimeout: setTimeout
};
vm.createContext(sandbox);

const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'estandar-form-engine.js'), 'utf8');
vm.runInContext(source, sandbox);

const core = windowStub.__ESTANDAR_FORMS_ENGINE_TEST__;

/* ── Harness ─────────────────────────────────────────────────────────────── */
let passed = 0;
let failed = 0;
function check(label, ok) {
    if (ok) { passed++; console.log('  ✓ ' + label); }
    else { failed++; console.log('  ✗ ' + label); }
}
function valuesOf(map) {
    return (name) => Object.prototype.hasOwnProperty.call(map, name) ? map[name] : '';
}

check('test hook expone el núcleo puro',
    typeof core.matchesWhen === 'function' &&
    typeof core.reduceConditions === 'function' &&
    typeof core.stepVisibility === 'function');

/* ── 1 · matchesWhen ─────────────────────────────────────────────────────── */
console.log('1 · matchesWhen');
const w = (op, value) => ({ field: 'f', operator: op, value: value });
check('equals true', core.matchesWhen(w('equals', 'x'), valuesOf({ f: 'x' })));
check('equals false', !core.matchesWhen(w('equals', 'x'), valuesOf({ f: 'y' })));
check('not_equals', core.matchesWhen(w('not_equals', 'x'), valuesOf({ f: 'y' })));
check('contains', core.matchesWhen(w('contains', 'tia'), valuesOf({ f: 'santiago' })));
check('empty', core.matchesWhen(w('empty', ''), valuesOf({ f: '' })));
check('not_empty', core.matchesWhen(w('not_empty', ''), valuesOf({ f: 'v' })));
check('in match', core.matchesWhen(w('in', ['a', 'b']), valuesOf({ f: 'b' })));
check('in no-match', !core.matchesWhen(w('in', ['a', 'b']), valuesOf({ f: 'c' })));
check('not_in', core.matchesWhen(w('not_in', ['a']), valuesOf({ f: 'c' })));
check('sin field → true (neutro)', core.matchesWhen({}, valuesOf({})));
check('valor con espacios se trimea', core.matchesWhen(w('equals', 'x'), valuesOf({ f: '  x  ' })));

/* ── 2 · reduceConditions (config estilo caso de aceptación) ─────────────── */
console.log('2 · reduceConditions');
const conditions = [
    { id: 'ciudad_otra',
      when: { field: 'ciudad', operator: 'equals', value: 'otra' },
      then: [
          { action: 'show', target: 'otra_ciudad' },
          { action: 'require', target: 'otra_ciudad' }
      ],
      else: [
          { action: 'hide', target: 'otra_ciudad' },
          { action: 'clear', target: 'otra_ciudad' },
          { action: 'optional', target: 'otra_ciudad' }
      ] },
    { id: 'necesidad_orientacion',
      when: { field: 'necesidad_principal', operator: 'in', value: ['financiamiento_subsidio', 'orientacion_emprender'] },
      then: [
          { action: 'require', target: 'mensaje' },
          { action: 'set_lead_quality', value: 'low_quality' },
          { action: 'set_lead_reason', value: 'Necesidad requiere orientación previa' }
      ],
      else: [
          { action: 'optional', target: 'mensaje' }
      ] }
];

let fx = core.reduceConditions(conditions, valuesOf({ ciudad: 'santiago', necesidad_principal: 'contabilidad_mensual' }));
check('ciudad≠otra: otra_ciudad oculta', fx.visibility.otra_ciudad === false);
check('ciudad≠otra: otra_ciudad limpiable', fx.clear.otra_ciudad === true);
check('ciudad≠otra: otra_ciudad opcional', fx.required.otra_ciudad === false);
check('necesidad core: mensaje opcional', fx.required.mensaje === false);
check('necesidad core: sin meta', !fx.meta.lead_quality && !fx.meta.lead_reason);

fx = core.reduceConditions(conditions, valuesOf({ ciudad: 'otra', necesidad_principal: 'contabilidad_mensual' }));
check('ciudad=otra: otra_ciudad visible', fx.visibility.otra_ciudad === true);
check('ciudad=otra: otra_ciudad requerida', fx.required.otra_ciudad === true);
check('ciudad=otra: sin clear', !fx.clear.otra_ciudad);

fx = core.reduceConditions(conditions, valuesOf({ ciudad: 'santiago', necesidad_principal: 'financiamiento_subsidio' }));
check('necesidad especial: mensaje requerido', fx.required.mensaje === true);
check('necesidad especial: lead_quality', fx.meta.lead_quality === 'low_quality');
check('necesidad especial: lead_reason', fx.meta.lead_reason === 'Necesidad requiere orientación previa');

fx = core.reduceConditions(conditions, valuesOf({ ciudad: 'otra', necesidad_principal: 'orientacion_emprender' }));
check('combinado: ambas ramas then activas', fx.required.otra_ciudad === true && fx.required.mensaje === true && fx.meta.lead_quality === 'low_quality');

// Literalidad: falla si low_quality llega transformado en el frontend.
check('literal: low_quality exacto, sin transformación',
    fx.meta.lead_quality === 'low_quality' &&
    fx.meta.lead_quality !== 'nurture' &&
    fx.meta.lead_quality !== 'lowquality' &&
    fx.meta.lead_quality !== 'low-quality');
check('literal: mayúsculas/espacios se preservan',
    (() => {
        const out = core.reduceConditions([{ when: { field: 'f', operator: 'equals', value: 'x' },
            then: [{ action: 'set_lead_quality', value: 'Low_Quality B2B' }] }], valuesOf({ f: 'x' }));
        return out.meta.lead_quality === 'Low_Quality B2B';
    })());

check('acciones desconocidas se ignoran',
    (() => {
        const out = core.reduceConditions([{ when: { field: 'f', operator: 'equals', value: 'x' }, then: [{ action: 'explode', target: 'a' }] }], valuesOf({ f: 'x' }));
        return Object.keys(out.visibility).length === 0 && Object.keys(out.required).length === 0;
    })());
check('sin condiciones → efectos vacíos',
    (() => {
        const out = core.reduceConditions([], valuesOf({}));
        return Object.keys(out.visibility).length === 0 && Object.keys(out.meta).length === 0;
    })());

/* ── 3 · stepVisibility (flujo progresivo) ───────────────────────────────── */
console.log('3 · stepVisibility');
const steps = [
    { id: 'perfil', fields: ['necesidad_principal'] },
    { id: 'contact', fields: ['nombre'], visible_when: { step_completed: 'perfil' } }
];

let vis = core.stepVisibility(steps, []);
check('carga inicial: perfil visible', vis.perfil === true);
check('carga inicial: contact oculto', vis.contact === false);

vis = core.stepVisibility(steps, ['perfil']);
check('perfil completado: contact visible', vis.contact === true);

vis = core.stepVisibility([
    { id: 'a', fields: ['x'] },
    { id: 'b', fields: ['y'], visible_when: { step_completed: 'a' } },
    { id: 'c', fields: ['z'], visible_when: { step_completed: 'b' } }
], ['a']);
check('cadena de 3 pasos: gate intermedio', vis.a === true && vis.b === true && vis.c === false);

check('paso sin visible_when siempre visible', core.stepVisibility([{ id: 's', fields: ['x'] }], []).s === true);

/* ── Resultado ───────────────────────────────────────────────────────────── */
console.log('\n' + passed + ' passed · ' + failed + ' failed');
process.exit(failed > 0 ? 1 : 0);
