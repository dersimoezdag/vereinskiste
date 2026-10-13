const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');

test('German date fields retain canonical form values, block invalid submit and support dynamic forms', async () => {
    const dom = new JSDOM('<form novalidate><input id="date" type="date" name="date" value="2026-10-13" min="2026-01-01" required><input id="money" name="amount" value="1234.56"><input id="iban" name="iban"><button>Save</button></form>', {runScripts: 'outside-only'});
    const w = dom.window, document = w.document;
    try {
        w.eval(readFileSync(require.resolve('../public/assets/input-masks.js'), 'utf8'));
        await new Promise(resolve => setTimeout(resolve, 20));
        assert.equal(document.querySelectorAll('input').length, 5);
        const date = document.querySelector('#date'), money = document.querySelector('#money'), iban = document.querySelector('#iban');
        assert.equal(date.value, '13.10.2026');
        for (const bad of ['29.02.2026', '31.04.2026', '31.12.2025']) {
            date.value = bad; date.dispatchEvent(new w.Event('input'));
            assert.equal(date.validity.valid, false);
            assert.equal(new w.FormData(date.form).get('date'), '');
        }
        date.value = '13.10.2026'; date.dispatchEvent(new w.Event('input'));
        assert.equal(date.validity.valid, true);
        assert.equal(new w.FormData(date.form).get('date'), '2026-10-13');
        money.dispatchEvent(new w.Event('blur')); assert.equal(money.value, '1.234,56');
        iban.value = 'de89 3704 0044 0532 0130 00'; iban.dispatchEvent(new w.Event('blur'));
        assert.equal(iban.value, 'DE89 3704 0044 0532 0130 00');
        let submits = 0; date.form.addEventListener('submit', () => submits++);
        date.value = '31.04.2026'; date.form.dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true}));
        assert.equal(submits, 0);
        date.value = '13.10.2026'; date.form.dispatchEvent(new w.Event('submit', {bubbles: true, cancelable: true}));
        assert.equal(submits, 1);
        date.form.reset(); assert.equal(date.value, '13.10.2026');
        await new Promise(resolve => setTimeout(resolve, 20));
        assert.equal(new w.FormData(date.form).get('date'), '2026-10-13');
        assert.equal(date.validity.valid, true);
        const field = document.createElement('input'); field.name = 'opening_balance'; field.value = '-12.5';
        date.form.appendChild(field); await new Promise(resolve => setTimeout(resolve, 20));
        field.dispatchEvent(new w.Event('blur')); assert.equal(field.value, '-12,50');
        assert.equal(document.querySelectorAll('input[type="hidden"][name="date"]').length, 1);
    } finally { w.close(); }
});
