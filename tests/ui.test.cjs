const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const script = fs.readFileSync(__dirname + '/../public/assets/ui.js', 'utf8');
function setup(html) {
    const dom = new JSDOM(`<body>${html}</body>`, {url:'https://verein.example.org',runScripts:'outside-only'});
    dom.window.eval(script);
    return {window:dom.window,document:dom.window.document};
}
const fire=(window,element,type,init={})=>element.dispatchEvent(new window.MouseEvent(type,{bubbles:true,cancelable:true,...init}));
const tipShown=document=>{const tip=document.getElementById('vk-tooltip');return !!tip&&tip.classList.contains('is-open');};

test('click on a help button keeps the tip open although hover and focus showed it first', () => {
    const {window,document}=setup('<label>Feld <button type="button" class="help-tooltip-btn" data-tip="Hilfetext">?</button></label><p id="away">x</p>');
    const button=document.querySelector('.help-tooltip-btn');
    fire(window,button,'mouseover');
    button.focus();
    fire(window,button,'click');
    assert.ok(tipShown(document),'tip stays visible after tap');
    assert.equal(document.getElementById('vk-tooltip').textContent,'Hilfetext');
    fire(window,button,'mouseout',{relatedTarget:document.body});
    assert.ok(tipShown(document),'pinned tip survives mouseout');
    fire(window,button,'click');
    assert.ok(!tipShown(document),'second click closes');
    fire(window,button,'click');
    fire(window,document.getElementById('away'),'click');
    assert.ok(!tipShown(document),'click outside closes');
    window.close();
});

test('scrolling an inner container hides the tip', () => {
    const {window,document}=setup('<div id="scroller"><button type="button" class="help-tooltip-btn" data-tip="Hilfe">?</button></div>');
    fire(window,document.querySelector('.help-tooltip-btn'),'click');
    assert.ok(tipShown(document));
    document.getElementById('scroller').dispatchEvent(new window.Event('scroll'));
    assert.ok(!tipShown(document));
    window.close();
});

test('backdrop click closes the dialog only when the press also started on the backdrop', () => {
    const {window,document}=setup('<dialog id="d" open><div class="modal-body"><input id="i"></div></dialog>');
    const dialog=document.getElementById('d');
    let closed=0; dialog.close=()=>{closed++;dialog.removeAttribute('open');};
    dialog.getBoundingClientRect=()=>({left:100,right:300,top:100,bottom:300});
    // Textauswahl: Druck im Feld, Loslassen ausserhalb -> click mit Ziel dialog
    fire(window,document.getElementById('i'),'pointerdown',{clientX:150,clientY:150});
    fire(window,dialog,'click',{clientX:20,clientY:20});
    assert.equal(closed,0);
    fire(window,dialog,'pointerdown',{clientX:20,clientY:20});
    fire(window,dialog,'click',{clientX:20,clientY:20});
    assert.equal(closed,1);
    window.close();
});

test('menus work without the Popover API', () => {
    const {window,document}=setup('<button id="t" popovertarget="m" aria-expanded="false">Menue</button><div class="menu" id="m" popover><a class="menu-item" href="#">x</a></div><p id="away">x</p>');
    const trigger=document.getElementById('t'), menu=document.getElementById('m');
    assert.ok(document.documentElement.classList.contains('no-popover'));
    fire(window,trigger,'click');
    assert.ok(menu.classList.contains('is-open'));
    assert.equal(trigger.getAttribute('aria-expanded'),'true');
    fire(window,document.getElementById('away'),'click');
    assert.ok(!menu.classList.contains('is-open'));
    assert.equal(trigger.getAttribute('aria-expanded'),'false');
    fire(window,trigger,'click');
    document.dispatchEvent(new window.KeyboardEvent('keydown',{key:'Escape'}));
    assert.ok(!menu.classList.contains('is-open'));
    window.close();
});
