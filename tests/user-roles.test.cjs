const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
function setup(boardChecked=false,adminChecked=false) {
    const dom = new JSDOM(`<fieldset data-user-roles><input type="checkbox" value="vorstand" ${boardChecked?'checked':''}><input type="checkbox" value="admin" ${adminChecked?'checked':''}></fieldset>`, {runScripts:'outside-only'});
    const {window}=dom;
    require('./browser-texts.cjs')(window);
    let calls=0;
    window.confirm=()=>{calls++;return false;};
    window.eval(fs.readFileSync(require.resolve('../public/assets/user-roles.js'),'utf8'));
    const board=window.document.querySelector('[value="vorstand"]'),admin=window.document.querySelector('[value="admin"]');
    return {window,board,admin,calls:()=>calls};
}
test('new board selection grants admin; manual revocation remains until a new assignment',()=>{
    const {window,board,admin,calls}=setup();
    board.click();assert.equal(admin.checked,true);
    admin.click();assert.equal(admin.checked,false);assert.equal(board.checked,true);
    board.click();assert.equal(calls(),0);
    board.click();assert.equal(admin.checked,true);
    window.close();
});
test('removing board asks whether to keep or remove admin',()=>{
    const {window,board,admin,calls}=setup(true,true);
    board.click();assert.equal(admin.checked,true);assert.equal(calls(),1);
    board.click();window.confirm=()=>true;
    board.click();assert.equal(admin.checked,false);
    window.close();
});
test('loading board without admin does not restore revoked admin',()=>{
    const {window,board,admin,calls}=setup(true,false);
    assert.equal(board.checked,true);assert.equal(admin.checked,false);assert.equal(calls(),0);
    window.close();
});
