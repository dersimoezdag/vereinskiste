const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
function setup() {
    const dom = new JSDOM(`<input id="own-password" type="password"><div data-admin-password><input type="password" data-password-input><button type="button" data-password-generate hidden></button><label data-password-visibility hidden><input type="checkbox" data-password-show></label><span data-password-status></span></div>`, {runScripts:'outside-only'});
    dom.window.vkText = () => 'Passwort generiert.';
    dom.window.eval(fs.readFileSync(require.resolve('../public/assets/user-password.js'), 'utf8'));
    return {window:dom.window,document:dom.window.document};
}
test('generator uses cryptographic randomness and fills only the admin password field',()=>{
    const {window,document}=setup();
    const input=document.querySelector('[data-password-input]');
    let changes=0; input.addEventListener('change',()=>changes++);
    const button=document.querySelector('[data-password-generate]');
    assert.equal(button.hidden,false);
    button.click();const first=input.value;
    assert.match(first,/^[A-Za-z0-9_-]{24}$/);assert.equal(input.type,'password');
    button.click();assert.notEqual(input.value,first);assert.equal(changes,2);
    assert.equal(document.querySelector('#own-password').value,'');
    assert.equal(document.querySelector('[data-password-status]').textContent,'Passwort generiert.');
    window.close();
});
test('show checkbox reveals and masks only the admin assignment password',()=>{
    const {window,document}=setup();
    const input=document.querySelector('[data-password-input]'),show=document.querySelector('[data-password-show]');
    assert.equal(document.querySelector('[data-password-visibility]').hidden,false);
    show.click();assert.equal(input.type,'text');
    assert.equal(document.querySelector('#own-password').type,'password');
    show.click();assert.equal(input.type,'password');
    window.close();
});

test('password mode toggles between invite and manual assignment', () => {
    const html = `
        <label><input type="radio" name="password_mode" value="invite" data-password-mode="invite" checked></label>
        <label><input type="radio" name="password_mode" value="manual" data-password-mode="manual"></label>
        <div data-admin-password hidden>
            <input type="password" data-password-input>
            <button type="button" data-password-generate hidden></button>
            <label data-password-visibility hidden><input type="checkbox" data-password-show></label>
        </div>
    `;
    const dom = new JSDOM(html, {runScripts: 'outside-only'});
    dom.window.vkText = () => 'Passwort generiert.';
    dom.window.eval(fs.readFileSync(require.resolve('../public/assets/user-password.js'), 'utf8'));

    const doc = dom.window.document;
    const group = doc.querySelector('[data-admin-password]');
    const input = doc.querySelector('[data-password-input]');
    const inviteRadio = doc.querySelector('[data-password-mode="invite"]');
    const manualRadio = doc.querySelector('[data-password-mode="manual"]');

    // Initially invite is checked: admin password is hidden and not required
    assert.equal(group.hidden, true);
    assert.equal(input.required, false);

    // Switch to manual
    inviteRadio.checked = false;
    manualRadio.checked = true;
    manualRadio.dispatchEvent(new dom.window.Event('change'));

    assert.equal(group.hidden, false);
    assert.equal(input.required, true);

    // Type a password
    input.value = 'ein-geheimes-passwort';

    // Switch back to invite
    manualRadio.checked = false;
    inviteRadio.checked = true;
    inviteRadio.dispatchEvent(new dom.window.Event('change'));

    assert.equal(group.hidden, true);
    assert.equal(input.required, false);
    assert.equal(input.value, '');

    dom.window.close();
});
