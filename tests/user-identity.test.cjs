const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {JSDOM}=require('jsdom');
test('linked person fields derive from member selection and become editable when unlinked',()=>{
 const dom=new JSDOM('<input data-linked-name><input data-linked-email><select id="user-member-id"><option value="">None</option><option value="1" data-member-name="Member" data-member-email="member@example.org">Member</option></select>',{runScripts:'outside-only'});
 dom.window.eval(fs.readFileSync(path.join(__dirname,'../public/assets/user-identity.js'),'utf8'));
 const select=dom.window.document.querySelector('select'),name=dom.window.document.querySelector('[data-linked-name]');select.value='1';select.dispatchEvent(new dom.window.Event('change'));assert.equal(name.value,'Member');assert.equal(name.readOnly,true);
 select.value='';select.dispatchEvent(new dom.window.Event('change'));assert.equal(name.readOnly,false);assert.equal(name.value,'Member');
});
