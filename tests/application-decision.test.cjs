const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');

const html = `<form data-application-decision
  data-template-approved-subject="Angenommen" data-template-approved-message="Willkommen"
  data-template-rejected-subject="Abgelehnt" data-template-rejected-message="Leider nein">
  <input type="radio" name="decision" value="approved" checked>
  <input type="radio" name="decision" value="rejected">
  <div data-decision-fields="approved">
    <input type="date" name="join_date" value="2026-12-01" required>
    <select name="membership_type_id"><option value="1">Aktives Mitglied</option><option value="2" selected>Passives Mitglied</option></select>
  </div>
  <div data-decision-fields="rejected" hidden><input name="reason" disabled></div>
  <p data-template-notice hidden><button type="button" data-template-apply></button></p>
  <input name="subject" value="Angenommen"><textarea name="message">Willkommen</textarea>
  <div data-decision-summary data-text-approved="{name} ab {date} als {type} an {email}" data-text-rejected="{name} abgelehnt"
       data-name="Erika" data-email="e@example.org"><span data-summary-text></span></div>
  <button type="submit" class="btn btn-success" data-step-submit data-label-approved="Annehmen" data-label-rejected="Ablehnen"><span data-submit-label></span></button>
</form>`;

function setup() {
    const dom = new JSDOM(html, {runScripts: 'outside-only'});
    const w = dom.window;
    require('./browser-texts.cjs')(w);
    w.eval(readFileSync(require.resolve('../public/assets/input-masks.js'), 'utf8'));
    w.eval(readFileSync(require.resolve('../public/assets/application-decision.js'), 'utf8'));
    const $ = (s) => w.document.querySelector(s);
    const choose = (value) => {
        const radio = $(`[name="decision"][value="${value}"]`);
        radio.checked = true;
        radio.dispatchEvent(new w.Event('change', {bubbles: true}));
    };
    return {w, $, choose};
}

test('summary and submit button follow the decision and approval fields', () => {
    const {w, $, choose} = setup();
    try {
        assert.equal($('[data-summary-text]').textContent, 'Erika ab 01.12.2026 als Passives Mitglied an e@example.org');
        assert.equal($('[data-submit-label]').textContent, 'Annehmen');
        choose('rejected');
        assert.equal($('[data-summary-text]').textContent, 'Erika abgelehnt');
        assert.equal($('[data-submit-label]').textContent, 'Ablehnen');
        assert.ok($('[data-step-submit]').classList.contains('btn-danger'));
        assert.ok(!$('[data-step-submit]').classList.contains('btn-success'));
    } finally { w.close(); }
});

test('switching decision toggles fields including the masked date and sends no approval data on rejection', () => {
    const {w, $, choose} = setup();
    try {
        choose('rejected');
        assert.equal($('[data-decision-fields="approved"]').hidden, true);
        assert.equal($('[data-decision-fields="rejected"]').hidden, false);
        const data = new w.FormData($('form'));
        assert.equal(data.get('join_date'), null);
        assert.equal(data.get('membership_type_id'), null);
        assert.equal($('[name="reason"]').disabled, false);
        choose('approved');
        assert.equal(new w.FormData($('form')).get('join_date'), '2026-12-01');
    } finally { w.close(); }
});

test('untouched message follows the decision, edited message asks before replacing', () => {
    const {w, $, choose} = setup();
    try {
        choose('rejected');
        assert.equal($('[name="subject"]').value, 'Abgelehnt');
        assert.equal($('[name="message"]').value, 'Leider nein');
        assert.equal($('[data-template-notice]').hidden, true);
        $('[name="message"]').value = 'Eigener Text';
        choose('approved');
        assert.equal($('[name="message"]').value, 'Eigener Text');
        assert.equal($('[data-template-notice]').hidden, false);
        $('[data-template-apply]').click();
        assert.equal($('[name="subject"]').value, 'Angenommen');
        assert.equal($('[name="message"]').value, 'Willkommen');
        assert.equal($('[data-template-notice]').hidden, true);
    } finally { w.close(); }
});
