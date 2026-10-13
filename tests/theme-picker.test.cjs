const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const script = fs.readFileSync(__dirname + '/../public/assets/app.js', 'utf8');
function setup(stored) {
    const dom = new JSDOM(`<details data-theme-picker><summary></summary><span data-theme-icon="light"></span><span data-theme-icon="dark"></span><span data-theme-icon="system"></span><input data-theme-choice type="radio" name="theme" value="light"><input data-theme-choice type="radio" name="theme" value="dark"><input data-theme-choice type="radio" name="theme" value="system"></details>`, {url:'https://verein.example.org',runScripts:'outside-only'});
    let onChange;
    const media = {matches:false, addEventListener(type, fn) {onChange = fn;}};
    dom.window.matchMedia = () => media;
    if(stored) dom.window.localStorage.setItem('vk-theme',stored);
    require('./browser-texts.cjs')(dom.window); dom.window.eval(script);
    return {window:dom.window,document:dom.window.document,media,systemChange() {onChange();}};
}
test('explicit selection persists and changes current icon; Escape restores focus', () => {
    const {window,document} = setup('light');
    const picker=document.querySelector('details');
    assert.equal(document.documentElement.dataset.bsTheme,'light');
    picker.open=true;
    assert.equal(window.localStorage.getItem('vk-theme'),'light'); // opening never toggles
    const input=document.querySelector('[value="dark"]');
    input.checked=true;input.dispatchEvent(new window.Event('change',{bubbles:true}));
    assert.equal(document.documentElement.dataset.bsTheme,'dark');
    assert.equal(window.localStorage.getItem('vk-theme'),'dark');
    assert.equal(document.querySelector('[data-theme-icon="dark"]').hidden,false);
    assert.equal(document.querySelector('[data-theme-icon="light"]').hidden,true);
    assert.equal(picker.open,false);
    picker.open=true;picker.dispatchEvent(new window.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
    assert.equal(picker.open,false);
    assert.equal(document.activeElement,document.querySelector('summary'));
    window.close();
});
test('system follows OS changes, explicit choices do not; other tabs synchronize', () => {
    const {window,document,media,systemChange}=setup();
    assert.equal(document.querySelector('[value="system"]').checked,true);
    media.matches=true;systemChange();
    assert.equal(document.documentElement.dataset.bsTheme,'dark');
    window.localStorage.setItem('vk-theme','light');
    window.dispatchEvent(new window.StorageEvent('storage',{key:'vk-theme'}));
    systemChange();assert.equal(document.documentElement.dataset.bsTheme,'light');
    window.localStorage.removeItem('vk-theme');
    window.dispatchEvent(new window.StorageEvent('storage',{key:null}));
    assert.equal(document.documentElement.dataset.bsTheme,'dark');
    window.close();
});
