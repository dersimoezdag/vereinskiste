const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');
const script = readFileSync(require.resolve('../public/assets/postal-places.js'), 'utf8');
const wait = () => new Promise(resolve => setTimeout(resolve, 450));
function setup(fetch) {
    const dom = new JSDOM('<form><input name="zip"><input name="city"><input name="country" value="Deutschland"></form>', {runScripts: 'outside-only'});
    const w = dom.window;
    require('./browser-texts.cjs')(w);
    w.fetch = fetch; w.eval(readFileSync(require.resolve('../public/assets/suggestions.js'), 'utf8')); w.eval(script);
    return {w, zip: w.document.querySelector('[name="zip"]'), city: w.document.querySelector('[name="city"]'), country: w.document.querySelector('[name="country"]')};
}
test('postcode lookup debounces, filters and deduplicates results, caches and leaves city untouched', async () => {
    const calls = [];
    const {w, zip, city} = setup(async url => {
        calls.push(url);
        return {ok: true, json: async () => ({features: [
            {properties: {countrycode: 'DE', postcode: '01067', city: 'Dresden'}},
            {properties: {countrycode: 'DE', postcode: '01067', city: 'Dresden'}},
            {properties: {countrycode: 'DE', postcode: '01068', city: 'Wrong'}},
            {properties: {countrycode: 'AT', postcode: '01067', city: 'Wrong'}}
        ]})};
    });
    try {
        city.value = 'Eigener Ort';
        for (const value of ['0', '0106', '01067']) { zip.value = value; zip.dispatchEvent(new w.Event('input')); }
        await wait();
        assert.equal(calls.length, 1); assert.match(calls[0], /q=01067/);
        assert.deepEqual(Array.from(w.document.querySelectorAll('[role="option"]'), option => option.textContent), ['Dresden']);
        assert.equal(city.value, 'Eigener Ort');
        zip.dispatchEvent(new w.Event('blur')); await wait(); assert.equal(calls.length, 1);
    } finally { w.close(); }
});
test('incomplete, excessive and foreign postcodes do not trigger requests', async () => {
    let calls = 0;
    const {w, zip, country} = setup(async () => { calls++; });
    try {
        zip.value = '123456'; zip.dispatchEvent(new w.Event('input')); await wait();
        zip.value = '1234'; zip.dispatchEvent(new w.Event('input')); await wait();
        country.value = 'Österreich'; zip.value = '12345'; zip.dispatchEvent(new w.Event('input')); await wait();
        assert.equal(calls, 0);
    } finally { w.close(); }
});
test('lookup failure preserves manual entry and country change discards stale responses', async () => {
    let resolve;
    const {w, zip, city, country} = setup(() => new Promise(done => { resolve = done; }));
    try {
        zip.value = '91054'; zip.dispatchEvent(new w.Event('input')); await wait();
        country.value = 'Österreich'; country.dispatchEvent(new w.Event('change'));
        resolve({ok: true, json: async () => ({features: [{properties: {countrycode: 'DE', postcode: '91054', city: 'Erlangen'}}]})});
        await new Promise(done => setTimeout(done, 10));
        assert.equal(w.document.querySelectorAll('[role="option"]').length, 0);
        w.fetch = async () => { throw new Error('offline'); };
        country.value = 'Deutschland'; country.dispatchEvent(new w.Event('change'));
        zip.value = '01067'; zip.dispatchEvent(new w.Event('input')); await wait();
        city.value = 'Dresden';
        assert.equal(city.disabled, false);
        assert.match(w.document.querySelector('[role="status"]').textContent, /nicht verfügbar/);
    } finally { w.close(); }
});
test('real Photon postcode area shapes resolve city names and use reverse lookup when absent', async () => {
    const calls = [];
    const {w, zip} = setup(async url => {
        calls.push(url);
        return {ok: true, json: async () => ({features: url.includes('/reverse?')
            ? [{properties: {countrycode: 'DE', postcode: '91054', city: 'Erlangen'}}]
            : [{properties: {countrycode: 'DE', osm_key: 'place', osm_value: 'postcode', name: '91054', county: 'Erlangen'}, geometry: {coordinates: [11.026, 49.602]}}]
        })};
    });
    try {
        zip.value = '91054'; zip.dispatchEvent(new w.Event('input')); await wait();
        assert.equal(calls.length, 2);
        assert.deepEqual(Array.from(w.document.querySelectorAll('[role="option"]'), option => option.textContent), ['Erlangen']);
    } finally { w.close(); }
});
