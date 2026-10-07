const fs = require('fs');
const path = require('path');

const js = fs.readFileSync(path.join(__dirname, '..', 'assets/js/estandar-forms-public.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '..', 'assets/css/estandar-forms.css'), 'utf8');
const start = js.indexOf('function replaceWithRecontactScreen');
const end = js.indexOf('function syncFormSuccessInstances', start);
const renderer = js.slice(start, end);
const cssStart = css.indexOf('/* Recontact screen visual contract.');
const cssEnd = css.indexOf('.estandar-form-error {', cssStart);
const recontactCss = css.slice(cssStart, cssEnd);

let passed = 0;
let failed = 0;
function check(label, condition) {
    if (condition) { passed++; console.log('PASS  ' + label); }
    else { failed++; console.log('FAIL  ' + label); }
}

check('renderer exists', start >= 0 && end > start);
check('renderer contains no inline style writes', !/\.style\.|style=|cssText/.test(renderer));
check('renderer contains no visual mouseenter/mouseleave handlers', !/mouseenter|mouseleave/.test(renderer));
check('renderer uses structural recontact classes', [
    'estandar-recontact-title', 'estandar-recontact-description', 'estandar-recontact-question',
    'estandar-recontact-action', 'estandar-recontact-action-icon', 'estandar-recontact-action-label',
    'estandar-recontact-detail', 'estandar-recontact-textarea', 'estandar-recontact-submit',
    'estandar-recontact-confirmation'
].every((name) => renderer.includes(name)));
check('renderer uses hidden attribute for visual visibility state', renderer.includes('textareaWrap.hidden = true') && renderer.includes('box.hidden = true'));

const tokens = ['background', 'surface', 'text', 'muted', 'border', 'accent', 'success', 'error'];
check('component defines required tokens', tokens.every((name) => css.includes('--estandar-recontact-' + name + ':')));
check('action explicitly owns color and webkit fill', /\.estandar-recontact-action\s*\{[\s\S]*?color:\s*var\(--estandar-recontact-text\);[\s\S]*?-webkit-text-fill-color:\s*var\(--estandar-recontact-text\);/.test(css));
check('action children explicitly own color and webkit fill', /\.estandar-recontact-action\s*>\s*\*\s*\{[\s\S]*?-webkit-text-fill-color:/.test(css));
check('states normal hover active focus-visible disabled exist', [':hover', ':active', ':focus-visible', ':disabled', '.is-selected'].every((state) => css.includes(state)));
check('recontact contract contains no important declarations', !recontactCss.includes('!important'));
check('recontact renderer and CSS do not reference esvy', !renderer.includes('esvy-') && !/estandar-recontact[^}]*esvy-/s.test(css));

function luminance(hex) {
    const rgb = hex.match(/[a-f\d]{2}/gi).map((v) => parseInt(v, 16) / 255).map((v) => v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4));
    return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
}
function contrast(a, b) {
    const values = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (values[0] + 0.05) / (values[1] + 0.05);
}
check('body text contrast is WCAG AA', contrast('#111827', '#ffffff') >= 4.5);
check('muted text contrast is WCAG AA', contrast('#4b5563', '#ffffff') >= 4.5);
check('accent button contrast is WCAG AA', contrast('#ffffff', '#c2410c') >= 4.5);
check('disabled button contrast is WCAG AA before opacity', contrast('#ffffff', '#4b5563') >= 4.5);

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
