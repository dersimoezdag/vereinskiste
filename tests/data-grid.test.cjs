const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const script = fs.readFileSync(__dirname + '/../public/assets/data-grid.js', 'utf8');

function markup(values) {
    return `<section data-grid><input data-grid-search><table><thead><tr><th><button data-grid-sort="0">Name <span data-grid-sort-icon></span></button></th><th><button data-grid-sort="1">Datum <span data-grid-sort-icon></span></button></th></tr></thead><tbody data-grid-body>${values.map(([name, date]) => `<tr data-grid-row><td data-grid-value="${name}">${name}<details><summary>Details</summary>Zusatzinfo</details></td><td data-grid-value="${date}">${date}<form><input name="version" value="1"></form></td></tr>`).join('')}<tr data-grid-empty hidden><td>Leer</td></tr><tr data-grid-no-results hidden><td>Keine Treffer</td></tr></tbody></table><footer data-grid-controls hidden><span data-grid-count></span><select data-grid-page-size><option>10</option><option selected>25</option><option value="all">Alle</option></select><button data-grid-prev>Zurück</button><span data-grid-page></span><button data-grid-next>Weiter</button></footer></section>`;
}
function setup(values, twice = false) {
    const dom = new JSDOM(markup(values) + (twice ? markup(values) : ''), {runScripts: 'outside-only'});
    dom.window.vkText = () => '{start}–{end} von {filtered} Treffern · {total} geladen';
    dom.window.eval(script);
    dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
    return dom;
}
const visible = grid => [...grid.querySelectorAll('[data-grid-row]')].filter(row => !row.hidden);
function change(dom, selector, value, type = 'input', grid = dom.window.document) {
    const element = grid.querySelector(selector);
    element.value = value;
    element.dispatchEvent(new dom.window.Event(type));
}

test('pagination, page size and all operate on loaded rows without losing nodes', () => {
    const dom = setup(Array.from({length: 31}, (_, i) => [`Person ${i}`, '2026-11-05']));
    const doc = dom.window.document;
    assert.equal(visible(doc).length, 25);
    assert.equal(doc.querySelector('[data-grid-prev]').disabled, true);
    doc.querySelector('[data-grid-next]').click();
    assert.equal(visible(doc).length, 6);
    assert.equal(doc.querySelector('[data-grid-next]').disabled, true);
    change(dom, '[data-grid-page-size]', 'all', 'change');
    assert.equal(visible(doc).length, 31);
    assert.equal(doc.querySelector('[data-grid-count]').textContent, '1–31 von 31 Treffern · 31 geladen');
    dom.window.close();
});

test('search matches words, accents and hidden details and resets pagination', () => {
    const dom = setup([['Müller Erika', '2026-11-05'], ['Muster Max', '2026-01-01']]);
    change(dom, '[data-grid-search]', 'erika muller');
    assert.equal(visible(dom.window.document).length, 1);
    change(dom, '[data-grid-search]', 'Zusatzinfo');
    assert.equal(visible(dom.window.document).length, 2);
    change(dom, '[data-grid-search]', '<img src=x>');
    assert.equal(visible(dom.window.document).length, 0);
    assert.equal(dom.window.document.querySelector('[data-grid-no-results]').hidden, false);
    assert.equal(dom.window.document.querySelector('img'), null);
    change(dom, '[data-grid-search]', '');
    assert.equal(visible(dom.window.document).length, 2);
    dom.window.close();
});

test('sort uses canonical dates and natural numbers, preserves forms and open details', () => {
    const dom = setup([['Person 10', '2026-01-03'], ['Person 2', '2025-12-31']]);
    const doc = dom.window.document;
    const original = doc.querySelector('[data-grid-row]');
    original.querySelector('details').open = true;
    original.querySelector('input').value = '2';
    const name = doc.querySelector('[data-grid-sort="0"]');
    name.click();
    assert.equal(visible(doc)[0].cells[0].dataset.gridValue, 'Person 2');
    assert.equal(name.closest('th').getAttribute('aria-sort'), 'ascending');
    name.click();
    assert.equal(visible(doc)[0], original);
    assert.equal(original.querySelector('details').open, true);
    assert.equal(original.querySelector('input').value, '2');
    doc.querySelector('[data-grid-sort="1"]').click();
    assert.equal(visible(doc)[0].cells[1].dataset.gridValue, '2025-12-31');
    assert.equal(name.closest('th').hasAttribute('aria-sort'), false);
    dom.window.close();
});

test('empty state and multiple grids remain independent; initialization is idempotent', () => {
    const empty = setup([]);
    assert.equal(empty.window.document.querySelector('[data-grid-empty]').hidden, false);
    assert.equal(empty.window.document.querySelector('[data-grid-no-results]').hidden, true);
    empty.window.close();
    const dom = setup([['Erika', '2026-01-01']], true);
    const grids = dom.window.document.querySelectorAll('[data-grid]');
    dom.window.eval(script);
    change(dom, '[data-grid-search]', 'nobody', 'input', grids[0]);
    assert.equal(visible(grids[0]).length, 0);
    assert.equal(visible(grids[1]).length, 1);
    dom.window.close();
});

test('numeric columns sort negative and fractional amounts correctly', () => {
    const dom = setup([['A', '-2.50'], ['B', '-10.00'], ['C', '0'], ['D', '1.25']]);
    const doc = dom.window.document;
    doc.querySelectorAll('[data-grid-row]').forEach(row => { row.cells[1].dataset.gridType = 'number'; });
    doc.querySelector('[data-grid-sort="1"]').click();
    assert.deepEqual(visible(doc).map(row => row.cells[1].dataset.gridValue), ['-10.00', '-2.50', '0', '1.25']);
    dom.window.close();
});

test('searching from the last page returns the first matching page', () => {
    const dom = setup(Array.from({length: 30}, (_, i) => [i === 0 ? 'Erika' : `Person ${i}`, '2026-11-05']));
    const doc = dom.window.document;
    doc.querySelector('[data-grid-next]').click();
    change(dom, '[data-grid-search]', 'Erika');
    assert.equal(visible(doc).length, 1);
    assert.equal(doc.querySelector('[data-grid-page]').textContent, '1 / 1');
    assert.equal(doc.querySelector('[data-grid-prev]').disabled, true);
    dom.window.close();
});
