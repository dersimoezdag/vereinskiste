const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');
const script = readFileSync(require.resolve('../public/assets/settings-navigation.js'), 'utf8');
function setup(mode, path) {
    const dom = new JSDOM(`<div data-navigation-panel data-navigation-mode="${mode}"><a href="/" data-application-return>Back</a><nav></nav><a href="/settings" data-settings-entry>Settings</a></div>`, {url: 'https://example.org' + path, runScripts: 'outside-only'});
    dom.window.matchMedia = () => ({matches: false});
    return dom;
}
test('settings return to the last local application page including filters', () => {
    const dom = setup('settings', '/settings/backup');
    try {
        dom.window.sessionStorage.setItem('vk.application-return', '/members?status=active#list');
        dom.window.eval(script);
        assert.equal(dom.window.document.querySelector('[data-application-return]').getAttribute('href'), '/members?status=active#list');
    } finally { dom.window.close(); }
});
test('external stored destinations cannot become return links', () => {
    for (const value of ['https://other.example/', '//other.example/', '/\\other.example/']) {
        const dom = setup('settings', '/settings');
        try {
            dom.window.sessionStorage.setItem('vk.application-return', value);
            dom.window.eval(script);
            assert.equal(dom.window.document.querySelector('[data-application-return]').getAttribute('href'), '/');
        } finally { dom.window.close(); }
    }
});
test('application pages remember their current location', () => {
    const dom = setup('application', '/members?status=active');
    try {
        dom.window.eval(script);
        assert.equal(dom.window.sessionStorage.getItem('vk.application-return'), '/members?status=active');
    } finally { dom.window.close(); }
});
test('entry motion runs once, respects reduced motion and survives initial pageshow', () => {
    for (const reduced of [false, true]) {
        const dom = setup('settings', '/settings'), w = dom.window;
        try {
            let calls = 0, cancelled = 0;
            w.matchMedia = () => ({matches: reduced});
            w.Element.prototype.animate = () => { calls++; return {cancel() { cancelled++; }}; };
            w.sessionStorage.setItem('vk.navigation-transition', JSON.stringify({to: 'settings', at: Date.now()}));
            w.eval(script);
            assert.equal(calls, reduced ? 0 : 1);
            w.dispatchEvent(new w.PageTransitionEvent('pageshow', {persisted: false}));
            assert.equal(cancelled, 0);
            w.dispatchEvent(new w.PageTransitionEvent('pageshow', {persisted: true}));
            assert.equal(cancelled, reduced ? 0 : 1);
            assert.equal(w.sessionStorage.getItem('vk.navigation-transition'), '');
        } finally { w.close(); }
    }
});

test('settings entry moves the button upward and keeps modified clicks native', () => {
    const dom = setup('application', '/members'), w = dom.window;
    try {
        const motions = [];
        w.Element.prototype.animate = function (frames) { motions.push({element: this, frames}); return {finished: new Promise(() => {}), cancel() {}}; };
        const panel = w.document.querySelector('[data-navigation-panel]'), entry = w.document.querySelector('[data-settings-entry]');
        panel.getBoundingClientRect = () => ({top: 50}); entry.getBoundingClientRect = () => ({top: 400});
        w.eval(script);
        const modified = new w.MouseEvent('click', {bubbles: true, cancelable: true, ctrlKey: true});
        entry.dispatchEvent(modified);
        assert.equal(modified.defaultPrevented, false);
        assert.equal(motions.length, 0);
        const click = new w.MouseEvent('click', {bubbles: true, cancelable: true});
        entry.dispatchEvent(click);
        assert.equal(click.defaultPrevented, true);
        assert.equal(motions[1].element, entry);
        assert.equal(motions[1].frames[1].transform, 'translateY(-350px)');
        assert.equal(JSON.parse(w.sessionStorage.getItem('vk.navigation-transition')).to, 'settings');
    } finally { w.close(); }
});
test('an open mobile menu remains open across the mode switch', () => {
    const dom = setup('settings', '/settings'), w = dom.window;
    try {
        w.document.body.insertAdjacentHTML('beforeend', '<button data-nav-toggle aria-expanded="false"></button><div id="sidebar-menu"></div>');
        w.sessionStorage.setItem('vk.navigation-transition', JSON.stringify({to: 'settings', at: Date.now(), menuOpen: true}));
        w.eval(script);
        assert.equal(w.document.getElementById('sidebar-menu').classList.contains('is-open'), true);
        assert.equal(w.document.querySelector('[data-nav-toggle]').getAttribute('aria-expanded'), 'true');
    } finally { w.close(); }
});
