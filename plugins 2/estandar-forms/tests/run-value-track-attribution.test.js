'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'estandar-attribution.js'), 'utf8');
const storage = {};
const localStorage = {
    getItem: key => Object.prototype.hasOwnProperty.call(storage, key) ? storage[key] : null,
    setItem: (key, value) => { storage[key] = String(value); }
};
const sessionStorage = { getItem: () => null, setItem: () => {} };

function run(url) {
    const window = {
        location: { href: url, search: new URL(url).search },
        localStorage,
        sessionStorage,
        addEventListener: () => {}
    };
    const document = {
        referrer: '', cookie: '',
        addEventListener: () => {},
        querySelectorAll: () => []
    };
    const sandbox = { window, document, localStorage, sessionStorage, URLSearchParams, Date, console };
    vm.createContext(sandbox);
    vm.runInContext(source, sandbox);
    return window.estandarAttribution;
}

let failed = 0;
function check(label, ok) {
    console.log((ok ? 'PASS  ' : 'FAIL  ') + label);
    if (!ok) failed++;
}

const first = run('https://example.test/?gad_campaignid=100&adgroup_id=200&keyword=casa&match_type=e&network=g');
check('first touch captures ValueTrack', first.first_touch.campaign_id === '100' && first.first_touch.adgroup_id === '200');

const second = run('https://example.test/next?campaignid=300&assetgroupid=400&network=x');
check('first touch remains immutable', second.first_touch.campaign_id === '100' && second.first_touch.adgroup_id === '200');
check('submission touch remains independent', second.last_touch.campaign_id === '300' && second.last_touch.asset_group_id === '400');

const third = run('https://example.test/final?campaign_id=&asset_group_id=');
check('empty later parameters do not erase valid values', third.last_touch.campaign_id === '300' && third.last_touch.asset_group_id === '400');

process.exit(failed === 0 ? 0 : 1);
