const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');

const html = `<p data-stepper-status></p><div data-stepper-bar></div>
<form novalidate data-application-stepper>
 <section data-step id="schritt-1"><h2 tabindex="-1">Person</h2><input name="last_name" required><button type="button" data-step-next>Weiter</button></section>
 <section data-step id="schritt-2"><h2 tabindex="-1">Antrag</h2><input type="checkbox" name="declaration[1]" value="1" required>
  <div data-signature-pad data-signature-required><input type="hidden" name="signature[1]" value="" data-signature-value></div>
  <button type="button" data-step-prev>Zurück</button><button type="button" data-step-next>Weiter</button></section>
 <section data-step id="schritt-3"><h2 tabindex="-1">Foto</h2>
  <label><input type="radio" name="declaration[2]" value="yes" required><span>Ich willige ein</span></label>
  <label><input type="radio" name="declaration[2]" value="no" required><span>Ich willige nicht ein</span></label>
  <button type="button" data-step-prev>Zurück</button><button type="button" data-step-next>Weiter</button></section>
 <section data-step id="schritt-4"><h2 tabindex="-1">Abschluss</h2>
  <dd data-summary-choice="1"></dd><img data-summary-signature="1" hidden><dd data-summary-choice="2"></dd>
  <a href="#schritt-3" data-step-goto="2">Ändern</a>
  <button type="button" data-step-prev>Zurück</button><button type="submit" data-stepper-submit disabled>Absenden</button></section>
</form>`;

function setup(extra = '') {
    const dom = new JSDOM(html.replace('id="schritt-3"', 'id="schritt-3"' + extra), {runScripts: 'outside-only', url: 'https://verein.example.org/antrag/x'});
    const w = dom.window;
    w.HTMLElement.prototype.scrollIntoView = () => {};
    w.scrolls = []; w.scrollTo = (x, y) => w.scrolls.push([x, y]);
    require('./browser-texts.cjs')(w);
    w.eval(readFileSync(require.resolve('../public/assets/application-stepper.js'), 'utf8'));
    w.VkApplicationStepper.init();
    return {w, d: w.document, visible: () => [...w.document.querySelectorAll('[data-step]')].filter(s => !s.hidden).map(s => s.id)};
}

test('only the first step is visible and submit becomes available', () => {
    const {w, d, visible} = setup();
    try {
        assert.deepEqual(visible(), ['schritt-1']);
        assert.equal(d.querySelector('[data-stepper-submit]').disabled, false);
        assert.match(d.querySelector('[data-stepper-status]').textContent, /1 von 4/);
    } finally { w.close(); }
});

test('next blocks incomplete steps including missing signature', () => {
    const {w, d, visible} = setup();
    try {
        d.querySelector('#schritt-1 [data-step-next]').click();
        assert.deepEqual(visible(), ['schritt-1']);
        d.querySelector('[name="last_name"]').value = 'Muster';
        d.querySelector('#schritt-1 [data-step-next]').click();
        assert.deepEqual(visible(), ['schritt-2']);
        assert.equal(d.activeElement, d.querySelector('#schritt-2 h2'));
        assert.deepEqual(w.scrolls.at(-1), [0, 0], 'Weiter springt an den Seitenanfang');
        assert.equal(w.location.hash, '#schritt-2');
        d.querySelector('[name="declaration[1]"]').checked = true;
        d.querySelector('#schritt-2 [data-step-next]').click();
        assert.deepEqual(visible(), ['schritt-2']);
        assert.ok(d.querySelector('[data-signature-pad]').classList.contains('is-invalid'));
        d.querySelector('[data-signature-value]').value = 'data:image/png;base64,AAAA';
        d.querySelector('#schritt-2 [data-step-next]').click();
        assert.deepEqual(visible(), ['schritt-3']);
        d.querySelector('#schritt-3 [data-step-prev]').click();
        assert.deepEqual(visible(), ['schritt-2']);
    } finally { w.close(); }
});

test('summary shows choices and signature, change link jumps back', () => {
    const {w, d, visible} = setup();
    try {
        d.querySelector('[name="last_name"]').value = 'Muster';
        d.querySelector('[name="declaration[1]"]').checked = true;
        d.querySelector('[data-signature-value]').value = 'data:image/png;base64,AAAA';
        d.querySelector('[name="declaration[2]"][value="no"]').checked = true;
        for (const id of ['#schritt-1', '#schritt-2', '#schritt-3']) { d.querySelector(id + ' [data-step-next]').click(); }
        assert.deepEqual(visible(), ['schritt-4']);
        assert.equal(d.querySelector('[data-summary-choice="1"]').textContent, 'Bestätigt');
        assert.equal(d.querySelector('[data-summary-choice="2"]').textContent, 'Ich willige nicht ein');
        const img = d.querySelector('[data-summary-signature="1"]');
        assert.equal(img.hidden, false);
        assert.equal(img.getAttribute('src'), 'data:image/png;base64,AAAA');
        d.querySelector('[data-step-goto]').click();
        assert.deepEqual(visible(), ['schritt-3']);
    } finally { w.close(); }
});

test('server error opens the first step with an error', () => {
    const {w, visible} = setup(' data-step-error');
    try { assert.deepEqual(visible(), ['schritt-3']); } finally { w.close(); }
});

test('submit with an incomplete step jumps there instead of sending', () => {
    const {w, d, visible} = setup();
    try {
        let sent = 0; d.querySelector('form').addEventListener('submit', e => { if (!e.defaultPrevented) { sent++; } e.preventDefault(); });
        d.querySelector('form').dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true}));
        assert.equal(sent, 0);
        assert.deepEqual(visible(), ['schritt-1']);
    } finally { w.close(); }
});

test('signature place follows the city until it is edited by hand', () => {
    const dom = new JSDOM(`<form novalidate data-application-stepper>
 <section data-step id="schritt-1"><h2 tabindex="-1">Anschrift</h2><input name="city" value="Erlangen"><button type="button" data-step-next>Weiter</button></section>
 <section data-step id="schritt-2"><h2 tabindex="-1">Antrag</h2><input name="signed_place[1]" value="" data-signed-place data-auto-place="" required>
  <button type="button" data-step-prev>Zurück</button></section></form>`, {runScripts: 'outside-only', url: 'https://verein.example.org/antrag/x'});
    const w = dom.window, d = w.document;
    try {
        w.scrollTo = () => {};
        require('./browser-texts.cjs')(w);
        w.eval(readFileSync(require.resolve('../public/assets/application-stepper.js'), 'utf8'));
        w.VkApplicationStepper.init();
        const city = d.querySelector('[name="city"]'), place = d.querySelector('[data-signed-place]');
        d.querySelector('[data-step-next]').click();
        assert.equal(place.value, 'Erlangen');
        d.querySelector('[data-step-prev]').click(); city.value = 'Nürnberg'; d.querySelector('[data-step-next]').click();
        assert.equal(place.value, 'Nürnberg');
        place.value = 'Fürth';
        d.querySelector('[data-step-prev]').click(); city.value = 'Bamberg'; d.querySelector('[data-step-next]').click();
        assert.equal(place.value, 'Fürth', 'manuell geaenderter Ort bleibt');
    } finally { w.close(); }
});
