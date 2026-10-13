const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
function setup(auto=false) {
    const items=['a','b','c'].map(id=>`<div data-dashboard-item id="${id}"><input name="cards[]" value="${id}"><input data-position value="1"><button type="button" data-drag-handle>Griff</button><button type="button" data-move="up">Hoch</button><button type="button" data-move="down">Runter</button></div>`).join('');
    const dom=new JSDOM(`<form action="/dashboard/preferences" data-dashboard-form ${auto?'data-autosave':''}><input name="version" value="0"><div data-dashboard-list>${items}</div><span data-dashboard-status></span></form>`,{url:'https://verein.example.org',runScripts:'outside-only'});
    const {window}=dom;
    window.eval(fs.readFileSync(__dirname+'/../public/assets/home-dashboard.js','utf8'));
    const order=()=>Array.from(window.document.querySelector('[data-dashboard-list]').children).map(item=>item.id);
    const pointer=(target,type,props={})=>{const e=new window.Event(type,{bubbles:true,cancelable:true});Object.assign(e,{pointerId:1,button:0,clientX:0,clientY:0},props);target.dispatchEvent(e);};
    return {window,document:window.document,order,pointer};
}
test('keyboard controls reorder and update non-JS position inputs',()=>{
    const {window,document,order}=setup();
    document.querySelector('#b [data-move="up"]').click();
    assert.deepEqual(order(),['b','a','c']);
    assert.equal(document.querySelector('#b [data-position]').value,'1');
    document.querySelector('#b [data-move="down"]').click();
    assert.deepEqual(order(),['a','b','c']);window.close();
});
test('pointer drag changes order; cancellation restores it',()=>{
    const {window,document,order,pointer}=setup();
    const grip=document.querySelector('#a [data-drag-handle]'),target=document.querySelector('#c');
    target.getBoundingClientRect=()=>({top:100,left:0,width:200,height:100});
    document.elementFromPoint=()=>target;
    pointer(grip,'pointerdown');pointer(grip,'pointermove',{clientY:160});
    assert.deepEqual(order(),['b','c','a']);
    pointer(grip,'pointercancel');assert.deepEqual(order(),['a','b','c']);
    pointer(grip,'pointerdown');pointer(grip,'pointermove',{clientY:160});pointer(grip,'pointerup');
    assert.deepEqual(order(),['b','c','a']);
    assert.equal(document.querySelector('#a [data-position]').value,'3');window.close();
});
test('autosave serializes changes with the returned version and blocks conflicts',async()=>{
    const {window,document,order}=setup(true);
    const requests=[];
    window.fetch=(url,options)=>new Promise(resolve=>requests.push({version:options.body.get('version'),cards:options.body.getAll('cards[]'),resolve}));
    document.querySelector('#a [data-move="down"]').click();
    document.querySelector('#a [data-move="down"]').click();
    assert.equal(requests.length,1);assert.deepEqual(order(),['b','c','a']);
    requests[0].resolve({ok:true,json:async()=>({version:1})});
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(requests.length,2);assert.equal(requests[1].version,'1');assert.deepEqual(requests[1].cards,['b','c','a']);
    requests[1].resolve({ok:false,json:async()=>({error:'Bitte neu laden'})});
    await new Promise(resolve=>setImmediate(resolve));
    document.querySelector('#a [data-move="up"]').click();
    assert.equal(requests.length,2);assert.equal(document.querySelector('[data-dashboard-status]').textContent,'Bitte neu laden');
    window.close();
});
