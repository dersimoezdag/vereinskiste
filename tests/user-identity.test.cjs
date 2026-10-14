const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom');
test('linked person fields derive from member selection and become editable when unlinked',()=>{
 const dom=new JSDOM('<input data-linked-name><input data-linked-email><select id="user-member-id"><option value="">None</option><option value="1" data-member-name="Member" data-member-email="member@example.org">Member</option></select>',{runScripts:'outside-only'});
 dom.window.eval(fs.readFileSync(path.join(__dirname,'../public/assets/user-identity.js'),'utf8'));
 const select=dom.window.document.querySelector('select'),name=dom.window.document.querySelector('[data-linked-name]');select.value='1';select.dispatchEvent(new dom.window.Event('change'));assert.equal(name.value,'Member');assert.equal(name.disabled,true);
 select.value='';select.dispatchEvent(new dom.window.Event('change'));assert.equal(name.disabled,false);assert.equal(name.value,'Member');
});
test('existing member selection needs two clicks on the edit button to unlock',()=>{
 const dom=new JSDOM('<input data-linked-name><input data-linked-email><input data-member-link-unlocked value="0"><button data-member-link-unlock></button><select id="user-member-id" disabled><option value="1" selected data-member-name="Member">Member</option></select>',{runScripts:'outside-only'});
 dom.window.eval(fs.readFileSync(path.join(__dirname,'../public/assets/user-identity.js'),'utf8'));
 const select=dom.window.document.querySelector('select'),button=dom.window.document.querySelector('button');
 button.click();assert.equal(select.disabled,true);
 button.dispatchEvent(new dom.window.MouseEvent('dblclick'));
 assert.equal(select.disabled,false);assert.equal(button.hidden,true);
 assert.equal(dom.window.document.querySelector('[data-member-link-unlocked]').value,'1');
 assert.equal(dom.window.document.querySelector('[data-linked-name]').disabled,true);
});
