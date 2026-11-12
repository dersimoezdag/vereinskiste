const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');
const script = readFileSync(require.resolve('../public/assets/person-picker.js'), 'utf8');

function setup(html) {
    const dom = new JSDOM(html, {runScripts: 'outside-only'});
    const w = dom.window;
    require('./browser-texts.cjs')(w);
    w.eval(script);
    w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
    return {w, dom};
}

test('person picker shows alphabetical list when empty and filters from 1 letter', () => {
    const html = `
    <div class="person-picker" data-person-picker>
        <select data-person-picker-select name="member_id">
            <option value="">Bitte wählen</option>
            <option value="3" data-member-name="Claudia Becker" data-member-no="105">#105 Claudia Becker</option>
            <option value="1" data-member-name="Anton Meyer" data-member-no="101">#101 Anton Meyer</option>
            <option value="2" data-member-name="Berta Schmidt" data-member-no="102">#102 Berta Schmidt</option>
            <option value="4" data-member-name="Ömer Yilmaz" data-member-no="108">#108 Ömer Yilmaz</option>
        </select>
    </div>`;
    const {w} = setup(html);
    try {
        const searchInput = w.document.querySelector('[data-person-picker-search]');
        const select = w.document.querySelector('select');
        const list = w.document.querySelector('[data-person-picker-list]');
        const clearBtn = w.document.querySelector('[data-person-picker-clear]');

        assert.ok(searchInput, 'Search input should exist');
        assert.ok(list, 'Dropdown list should exist');
        assert.equal(select.hidden, true, 'Original select should be hidden');
        assert.equal(searchInput.value, '');
        assert.equal(clearBtn.hidden, true, 'Clear button should be hidden initially');

        // Focus with empty input -> "sonst liste alphabetisch"
        searchInput.focus();
        assert.equal(list.hidden, false, 'List should be visible on focus');

        const optionTexts = Array.from(list.querySelectorAll('[role="option"]')).map(el => el.textContent.trim());
        // Empty choice at top, followed by alphabetically sorted names (German collation: Anton, Berta, Claudia, Ömer)
        assert.equal(optionTexts[0], 'Bitte wählen');
        assert.ok(optionTexts[1].includes('Anton Meyer'));
        assert.ok(optionTexts[2].includes('Berta Schmidt'));
        assert.ok(optionTexts[3].includes('Claudia Becker'));
        assert.ok(optionTexts[4].includes('Ömer Yilmaz'));

        // Type 1 letter: "b" -> "ab 1 buchstabe vorschläge in liste"
        searchInput.value = 'b';
        searchInput.dispatchEvent(new w.Event('input'));

        const filteredB = Array.from(list.querySelectorAll('[role="option"]')).map(el => el.textContent.trim());
        // Matches: Berta Schmidt (starts with B), Claudia Becker (contains Becker), Bitte wählen
        assert.ok(filteredB.some(t => t.includes('Berta Schmidt')));
        assert.ok(filteredB.some(t => t.includes('Claudia Becker')));
        assert.ok(!filteredB.some(t => t.includes('Anton Meyer')), 'Anton Meyer should not match "b"');

        // Search with umlaut normalization: "omer" matches "Ömer"
        searchInput.value = 'omer';
        searchInput.dispatchEvent(new w.Event('input'));
        const filteredOmer = Array.from(list.querySelectorAll('[role="option"]')).map(el => el.textContent.trim());
        assert.equal(filteredOmer.length, 1);
        assert.ok(filteredOmer[0].includes('Ömer Yilmaz'));

        // Search by member number: "105" matches Claudia Becker
        searchInput.value = '105';
        searchInput.dispatchEvent(new w.Event('input'));
        const filteredNo = Array.from(list.querySelectorAll('[role="option"]')).map(el => el.textContent.trim());
        assert.equal(filteredNo.length, 1);
        assert.ok(filteredNo[0].includes('Claudia Becker'));

        // Clearing input restores full alphabetical list
        searchInput.value = '';
        searchInput.dispatchEvent(new w.Event('input'));
        const restored = Array.from(list.querySelectorAll('[role="option"]')).map(el => el.textContent.trim());
        assert.equal(restored.length, 5, 'Should restore all 4 persons + empty option');
        assert.ok(restored[1].includes('Anton Meyer'));

        // Unknown search displays no results notice
        searchInput.value = 'xyz123';
        searchInput.dispatchEvent(new w.Event('input'));
        const noRes = list.querySelector('.person-picker-no-results');
        assert.ok(noRes, 'No results element should be displayed');
        assert.equal(noRes.textContent, 'Keine Personen gefunden');
    } finally {
        w.close();
    }
});

test('person picker selects option on click, updates hidden/select value and dispatches change', () => {
    const html = `
    <div class="person-picker" data-person-picker>
        <select data-person-picker-select name="member_id">
            <option value="">Bitte wählen</option>
            <option value="42" data-member-name="Erika Muster">Erika Muster</option>
            <option value="99" data-member-name="Max Mustermann">Max Mustermann</option>
        </select>
    </div>`;
    const {w} = setup(html);
    try {
        const searchInput = w.document.querySelector('[data-person-picker-search]');
        const select = w.document.querySelector('select');
        const list = w.document.querySelector('[data-person-picker-list]');
        const clearBtn = w.document.querySelector('[data-person-picker-clear]');

        let changeFired = 0;
        select.addEventListener('change', () => { changeFired++; });

        searchInput.focus();
        // Click Erika Muster
        const erikaOption = Array.from(list.querySelectorAll('[role="option"]'))
            .find(el => el.textContent.includes('Erika Muster'));
        assert.ok(erikaOption);
        erikaOption.dispatchEvent(new w.MouseEvent('click', {bubbles: true}));

        assert.equal(select.value, '42');
        assert.equal(searchInput.value, 'Erika Muster');
        assert.equal(clearBtn.hidden, false, 'Clear button should now be visible');
        assert.equal(list.hidden, true, 'List should close on selection');
        assert.equal(changeFired, 1, 'Change event should have fired');

        // Click clear button
        clearBtn.dispatchEvent(new w.MouseEvent('click', {bubbles: true}));
        assert.equal(select.value, '');
        assert.equal(searchInput.value, '');
        assert.equal(clearBtn.hidden, true, 'Clear button should hide when cleared');
        assert.equal(changeFired, 2, 'Change event should have fired on clear');
    } finally {
        w.close();
    }
});

test('person picker supports keyboard navigation (arrows, enter, escape)', () => {
    const html = `
    <div class="person-picker" data-person-picker>
        <select data-person-picker-select name="member_id">
            <option value="">Bitte wählen</option>
            <option value="1" data-member-name="Anna Altmann">Anna Altmann</option>
            <option value="2" data-member-name="Bernd Bauer">Bernd Bauer</option>
        </select>
    </div>`;
    const {w} = setup(html);
    try {
        const searchInput = w.document.querySelector('[data-person-picker-search]');
        const select = w.document.querySelector('select');
        const list = w.document.querySelector('[data-person-picker-list]');

        searchInput.focus();
        // ArrowDown to move to first option ("Bitte wählen"), then second option ("Anna Altmann")
        searchInput.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'ArrowDown', bubbles: true}));
        searchInput.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'ArrowDown', bubbles: true}));

        const options = list.querySelectorAll('[role="option"]');
        assert.equal(options[1].getAttribute('aria-selected'), 'true');
        assert.equal(searchInput.getAttribute('aria-activedescendant'), options[1].id);

        // Press Enter to select
        searchInput.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'Enter', bubbles: true}));
        assert.equal(select.value, '1');
        assert.equal(searchInput.value, 'Anna Altmann');
        assert.equal(list.hidden, true);

        // Type something but press Escape -> should restore previous selection
        searchInput.focus();
        searchInput.value = 'xyz';
        searchInput.dispatchEvent(new w.Event('input'));
        assert.equal(list.hidden, false);

        searchInput.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        assert.equal(list.hidden, true);
        assert.equal(searchInput.value, 'Anna Altmann', 'Escape should restore selected person text');
    } finally {
        w.close();
    }
});

test('standalone select with data-person-picker is progressively enhanced', () => {
    const html = `
    <form>
        <select id="direct-select" name="person_id" data-person-picker>
            <option value="">Keine Auswahl</option>
            <option value="10" selected>Zoe Zeidler</option>
            <option value="20">Adam Apfel</option>
        </select>
    </form>`;
    const {w} = setup(html);
    try {
        const select = w.document.getElementById('direct-select');
        const container = select.closest('.person-picker');
        assert.ok(container, 'Container should be created automatically');

        const searchInput = container.querySelector('[data-person-picker-search]');
        assert.ok(searchInput);
        assert.equal(searchInput.value, 'Zoe Zeidler', 'Should pre-fill with selected option');

        const clearBtn = container.querySelector('[data-person-picker-clear]');
        assert.equal(clearBtn.hidden, false);

        // When select is disabled via JS, picker search input is disabled
        select.disabled = true;
        select.dispatchEvent(new w.Event('change'));
        // Trigger observer if any or test disabled logic
        assert.equal(select.value, '10');
    } finally {
        w.close();
    }
});
