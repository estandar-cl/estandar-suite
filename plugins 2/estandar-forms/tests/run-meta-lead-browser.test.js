/**
 * Contract test: Browser Meta Lead (estandar-forms-public.js, archivo REAL).
 * Cubre: event_id proviene de la respuesta del servidor · fallback
 * estrictamente equivalente (mismo uuid v4 posteado + ':CAPTURED') · sin
 * lead_uuid válido no se dispara · event_name 'Lead' · opt-out por form.
 * Sin framework: node tests/run-meta-lead-browser.test.js
 */
'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');

// ── Sandbox mínimo de navegador ──────────────────────────────────────────────
const noop = () => {};
const windowStub = {
    addEventListener: noop,
    location: { href: 'https://sitio.test/contacto', search: '' },
    localStorage: { getItem: () => null, setItem: noop, removeItem: noop },
    sessionStorage: { getItem: () => null, setItem: noop, removeItem: noop },
    crypto: { randomUUID: () => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' },
    dataLayer: [],
    console: console,
    __ESTANDAR_FORMS_TEST__: {}
};
const documentStub = {
    readyState: 'loading',
    addEventListener: noop,
    removeEventListener: noop,
    querySelector: () => null,
    querySelectorAll: () => [],
    getElementById: () => null,
    createElement: () => ({ style: {}, setAttribute: noop, appendChild: noop }),
    dispatchEvent: noop,
    referrer: '',
    cookie: '',
    body: { appendChild: noop }
};

const sandbox = {
    window: windowStub,
    document: documentStub,
    navigator: { userAgent: 'node-test', sendBeacon: noop },
    URLSearchParams: URLSearchParams,
    Image: function () { this.src = ''; sandbox.__images.push(this); },
    FormData: function () { this.append = noop; },
    fetch: () => new Promise(noop),
    CustomEvent: function (type, init) { this.type = type; this.detail = init && init.detail; },
    MutationObserver: function () { this.observe = noop; this.disconnect = noop; },
    setTimeout: setTimeout,
    clearTimeout: clearTimeout,
    console: console,
    __images: []
};
sandbox.window.URLSearchParams = URLSearchParams;

const source = fs.readFileSync(
    path.join(__dirname, '..', 'assets', 'js', 'estandar-forms-public.js'),
    'utf8'
);
vm.createContext(sandbox);
vm.runInContext(source, sandbox);

const hooks = windowStub.__ESTANDAR_FORMS_TEST__;
if (typeof hooks.trackMetaLead !== 'function' || typeof hooks.metaLeadEventId !== 'function') {
    console.error('FAIL  test hook no expuesto — abortando');
    process.exit(1);
}

// ── Harness ──────────────────────────────────────────────────────────────────
let pass = 0, fail = 0;
function check(label, cond) {
    if (cond) { pass++; console.log('PASS  ' + label); }
    else { fail++; console.log('FAIL  ' + label); }
}
function fakeForm(uuidValue, metaLeadEvent) {
    return {
        dataset: { metaLeadEvent: metaLeadEvent === undefined ? '1' : metaLeadEvent, name: 'Contacto' },
        querySelector: (sel) => {
            if (sel === 'input[name="lead_uuid"]') {
                return uuidValue === null ? null : { value: uuidValue };
            }
            return null;
        }
    };
}
let fbqCalls = [];
function armFbq() { fbqCalls = []; windowStub.fbq = function () { fbqCalls.push([].slice.call(arguments)); }; }
const V4 = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

// 1. El event_id proviene de la respuesta del servidor.
armFbq();
hooks.trackMetaLead(fakeForm(V4), { meta_lead_event_id: 'srv-uuid-77:CAPTURED' });
check('Browser usa el event_id de la respuesta del servidor', fbqCalls.length === 1 && fbqCalls[0][3].eventID === 'srv-uuid-77:CAPTURED');
check("Browser emite event_name 'Lead'", fbqCalls[0][0] === 'track' && fbqCalls[0][1] === 'Lead');

// 2. El id del servidor PREVALECE siempre — el browser no deriva nada propio.
armFbq();
hooks.trackMetaLead(fakeForm('11111111-2222-4333-8444-555555555555'), { meta_lead_event_id: 'srv-uuid-99:CAPTURED' });
check('server id usado aunque el form tenga otro uuid local', fbqCalls[0][3].eventID === 'srv-uuid-99:CAPTURED');

// 3. Sin id del servidor → NO hay Browser Lead (el frontend NUNCA reconstruye).
//    Persistencia fallida = respuesta sin meta_lead_event_id = cero eventos.
armFbq();
hooks.trackMetaLead(fakeForm(V4), {});
check('respuesta sin meta_lead_event_id → fbq NO llamado (aunque haya uuid local)', fbqCalls.length === 0);
armFbq();
hooks.trackMetaLead(fakeForm(V4), undefined);
check('sin ajaxData → fbq NO llamado', fbqCalls.length === 0);
armFbq();
hooks.trackMetaLead(fakeForm(V4), { meta_lead_event_id: '' });
check('meta_lead_event_id vacío → fbq NO llamado', fbqCalls.length === 0);
armFbq();
hooks.trackMetaLead(fakeForm(''), {});
check('sin uuid y sin server id → fbq NO llamado', fbqCalls.length === 0);

// 4. Opt-out por formulario.
armFbq();
hooks.trackMetaLead(fakeForm(V4, '0'), { meta_lead_event_id: 'srv:CAPTURED' });
check('form sin data-meta-lead-event → fbq NO llamado', fbqCalls.length === 0);

// 5. metaLeadEventId puro: solo consume el valor del servidor.
check('metaLeadEventId(ajaxData) retorna el id del servidor', hooks.metaLeadEventId({ meta_lead_event_id: 'x:CAPTURED' }) === 'x:CAPTURED');
check('metaLeadEventId sin valor del servidor → cadena vacía', hooks.metaLeadEventId({}) === '' && hooks.metaLeadEventId(undefined) === '');
check('el código del bundle no reconstruye <uuid>:CAPTURED client-side', source.indexOf("leadUuid + ':CAPTURED'") === -1);

// 7. Evidencia de call-site: trackMetaLead solo se invoca desde el branch de
//    éxito (post result.success) vía pushMarketingEvent(form, ajaxData).
const callSites = source.match(/pushMarketingEvent\(form(, ajaxData)?\)/g) || [];
check('todas las invocaciones pasan ajaxData del servidor',
    callSites.length >= 2 && callSites.every(s => s.includes('ajaxData')));
check('trackMetaLead no se invoca fuera de pushMarketingEvent',
    (source.match(/trackMetaLead\(/g) || []).length === 2); // definición + 1 call-site

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail === 0 ? 0 : 1);
