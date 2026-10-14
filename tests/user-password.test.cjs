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
