const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');

test('German date fields retain canonical form values, block invalid submit and support dynamic forms', async () => {
    const dom = new JSDOM('<form novalidate><input id="date" type="date" name="date" value="2026-10-13" min="2026-01-01" required><input id="money" name="amount" value="1234.56"><input id="iban" name="iban"><button>Save</button></form>', {runScripts: 'outside-only'});
    const w = dom.window, document = w.document;
    try {
        require('./browser-texts.cjs')(w); w.eval(readFileSync(require.resolve('../public/assets/input-masks.js'), 'utf8'));
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

test('invoice suggestions populate German masks, keep zero tax valid and retain ISO submission', () => {
    const dom = new JSDOM('<form id="invoice-form"><input id="invoice-invoice_date" type="date" name="invoice_date"><input id="invoice-net" name="net"><input id="invoice-tax" name="tax"><input id="invoice-gross" name="gross"><button type="button" id="apply"></button></form>', {runScripts: 'outside-only'});
    const w=dom.window, document=w.document;
    try {
        require('./browser-texts.cjs')(w); w.eval(readFileSync(require.resolve('../public/assets/input-masks.js'),'utf8'));
        require('./browser-texts.cjs')(w); w.eval(readFileSync(require.resolve('../public/assets/invoices.js'),'utf8'));
        const button=document.querySelector('#apply');
        button.dataset.invoiceSuggestions=JSON.stringify({invoice_date:'2026-10-13',net:'100.00',tax:'0',gross:'100.00'});
        button.click();
        assert.equal(document.querySelector('#invoice-invoice_date').value,'13.10.2026');
        assert.equal(new w.FormData(document.querySelector('form')).get('invoice_date'),'2026-10-13');
        assert.equal(document.querySelector('#invoice-tax').value,'0,00');
        assert.equal(document.querySelector('#invoice-tax').validity.valid,true);
        document.querySelector('#invoice-tax').value='-1';document.querySelector('#invoice-tax').dispatchEvent(new w.Event('input'));
        assert.equal(document.querySelector('#invoice-tax').validity.valid,false);
    } finally {w.close();}
});

test('money fields group while typing, keep the caret and still submit a valid value', async () => {
    const dom = new JSDOM('<form><input id="m" name="opening_balance" value=""><input id="p" data-mask="amount" name="planned_amount" value="20.50"></form>', {runScripts: 'outside-only'});
    const w = dom.window;
    w.vkText = (key) => key;
    w.eval(require('node:fs').readFileSync(require('node:path').join(__dirname, '../public/assets/input-masks.js'), 'utf8'));
    const field = w.document.querySelector('#m');
    const type = (value, caret) => { field.value = value; field.setSelectionRange(caret, caret); field.dispatchEvent(new w.Event('input')); };
    assert.equal(w.document.querySelector('#p').value, '20,50');
    type('2227', 4);
    assert.equal(field.value, '2.227');
    assert.equal(field.selectionStart, 5);
    assert.equal(field.validity.valid, true);
    type('2.227.', 6);
    assert.equal(field.value, '2.227,');
    type('2.227,59', 8);
    field.dispatchEvent(new w.Event('blur'));
    assert.equal(field.value, '2.227,59');
    type('12273,5', 2);
    assert.equal(field.value, '12.273,5');
    assert.equal(field.selectionStart, 2);
    type('2.227', 5);
    field.dispatchEvent(new w.Event('blur'));
    assert.equal(field.value, '2.227,00');
});

test('amount sign and direction select keep each other in sync', () => {
    const dom = new JSDOM('<form><select id="d" name="direction"><option value="in">in</option><option value="out" selected>out</option></select><input id="a" name="amount" data-mask="signed_amount" data-sign-select="d" value="12,50"></form>', {runScripts: 'outside-only'});
    const w = dom.window;
    w.vkText = (key) => key;
    w.eval(readFileSync(require('node:path').join(__dirname, '../public/assets/input-masks.js'), 'utf8'));
    const field = w.document.querySelector('#a'), select = w.document.querySelector('#d');
    const type = (value) => { field.value = value; field.setSelectionRange(value.length, value.length); field.dispatchEvent(new w.Event('input')); };
    assert.equal(field.value, '-12,50');
    assert.equal(field.classList.contains('amount-negative'), true);
    select.value = 'in'; select.dispatchEvent(new w.Event('change'));
    assert.equal(field.value, '12,50');
    assert.equal(field.classList.contains('amount-negative'), false);
    type('-12,50');
    assert.equal(select.value, 'out');
    type('12,50');
    assert.equal(select.value, 'in');
    type('+7');
    assert.equal(select.value, 'in');
    assert.equal(field.value, '7');
    type('');
    select.value = 'out'; select.dispatchEvent(new w.Event('change'));
    assert.equal(field.value, '');
    type('2227');
    assert.equal(field.value, '-2.227');
    assert.equal(select.value, 'out');
    assert.equal(field.validity.valid, true);
});

test('postal code field follows the country field of its form', async () => {
    const dom = new JSDOM('<form><input id="zip" name="zip"><input id="country" name="country" value="Deutschland"></form>', {runScripts: 'outside-only'});
    const w = dom.window, document = w.document;
    try {
        require('./browser-texts.cjs')(w); w.eval(readFileSync(require.resolve('../public/assets/input-masks.js'), 'utf8'));
        const zip = document.querySelector('#zip'), country = document.querySelector('#country');
        assert.equal(zip.placeholder, 'Postleitzahl, z. B. 91054');
        zip.value = '910 54'; zip.dispatchEvent(new w.Event('blur'));
        assert.equal(zip.value, '91054'); assert.equal(zip.validity.valid, true);
        zip.value = '1010'; zip.dispatchEvent(new w.Event('input'));
        assert.equal(zip.validity.valid, false);
        country.value = 'Österreich'; country.dispatchEvent(new w.Event('input'));
        assert.equal(zip.validity.valid, true);
    } finally { w.close(); }
});
