const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');
const script = readFileSync(require.resolve('../public/assets/email-suggestions.js'), 'utf8');

test('email suggestions start after @, filter domains and preserve the local part and manual addresses', () => {
    const dom = new JSDOM('<input type="email" name="email">', {runScripts: 'outside-only'});
    const w = dom.window;
    try {
        w.eval(script);
        const field = w.document.querySelector('input');
        function type(value) {
            field.value = value; field.dispatchEvent(new w.Event('input'));
            return Array.from(w.document.querySelectorAll('option'), option => option.value);
        }
        assert.deepEqual(type('Anna.Test+verein'), []);
        assert.ok(type('Anna.Test+verein@').includes('Anna.Test+verein@gmx.de'));
        assert.deepEqual(type('Anna.Test+verein@GM'), ['Anna.Test+verein@gmail.com', 'Anna.Test+verein@gmx.de', 'Anna.Test+verein@gmx.net']);
        assert.deepEqual(type('Anna.Test+verein@gmx.de'), []);
        assert.deepEqual(type('anna@eigener-verein.de'), []);
        assert.equal(field.value, 'anna@eigener-verein.de');
        assert.deepEqual(type('anna@@g'), []);
        assert.deepEqual(type(''), []);
    } finally { w.close(); }
});

test('dynamic email fields get independent lists while existing lists and readonly fields are preserved', async () => {
    const dom = new JSDOM('<input type="email" list="existing"><datalist id="existing"></datalist><input type="email" readonly>', {runScripts: 'outside-only'});
    const w = dom.window;
    try {
        w.eval(script);
        w.document.body.insertAdjacentHTML('beforeend', '<input type="email" maxlength="15"><input type="email">');
        await new Promise(resolve => setTimeout(resolve, 0));
        const fields = w.document.querySelectorAll('input');
        assert.equal(fields[0].getAttribute('list'), 'existing');
        assert.equal(fields[1].hasAttribute('list'), false);
        assert.notEqual(fields[2].getAttribute('list'), fields[3].getAttribute('list'));
        fields[2].value = 'longlocalpart@'; fields[2].dispatchEvent(new w.Event('input'));
        assert.equal(w.document.getElementById(fields[2].getAttribute('list')).children.length, 0);
        assert.equal(w.document.querySelectorAll('datalist').length, 3);
    } finally { w.close(); }
});
