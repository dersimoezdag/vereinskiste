const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const script = fs.readFileSync(__dirname + '/../public/assets/app.js', 'utf8');
function setup(stored) {
    const dom = new JSDOM(`<button data-theme-toggle aria-pressed="false" data-label-to-dark="Dunkel" data-label-to-light="Hell"></button>`, {url:'https://verein.example.org',runScripts:'outside-only'});
    let onChange;
    const media = {matches:false, addEventListener(type, fn) {onChange = fn;}};
    dom.window.matchMedia = () => media;
    if(stored) dom.window.localStorage.setItem('vk-theme',stored);
    require('./browser-texts.cjs')(dom.window); dom.window.eval(script);
    return {window:dom.window,document:dom.window.document,media,systemChange() {onChange();}};
}
test('toggle switches theme, persists it and updates aria state', () => {
    const {window,document} = setup('light');
    const button=document.querySelector('[data-theme-toggle]');
    assert.equal(document.documentElement.dataset.bsTheme,'light');
    assert.equal(button.getAttribute('aria-pressed'),'false');
    button.click();
    assert.equal(document.documentElement.dataset.bsTheme,'dark');
    assert.equal(window.localStorage.getItem('vk-theme'),'dark');
    assert.equal(button.getAttribute('aria-pressed'),'true');
    assert.equal(button.title,'Hell');
    button.click();
    assert.equal(document.documentElement.dataset.bsTheme,'light');
    window.close();
});
test('without stored choice the OS is followed; other tabs synchronize', () => {
    const {window,document,media,systemChange}=setup();
    media.matches=true;systemChange();
    assert.equal(document.documentElement.dataset.bsTheme,'dark');
    window.localStorage.setItem('vk-theme','light');
    window.dispatchEvent(new window.StorageEvent('storage',{key:'vk-theme'}));
    systemChange();assert.equal(document.documentElement.dataset.bsTheme,'light');
    window.close();
});
