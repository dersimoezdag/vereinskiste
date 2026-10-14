const test=require('node:test'), assert=require('node:assert/strict'), fs=require('node:fs');
const {JSDOM}=require('jsdom');
test('editor and PDF modes preserve text and submit the latest editor content',()=>{
 const dom=new JSDOM('<form data-document-form><select data-document-source><option value="editor">Editor</option><option value="pdf">PDF</option><option value="docx">DOCX</option></select><div data-document-editor><textarea data-document-content></textarea></div><div data-document-pdf><input name="receipt" type="file"></div></form><script src="https://local.test/assets/vendor/tinymce/tinymce.min.js?v=123"></script>',{url:'https://local.test/documents/minutes/new',runScripts:'outside-only'});
 const {window}=dom; let config,saves=0;
 window.tinymce={init:c=>{config=c;c.setup({on:(event,callback)=>{if(event==='init')callback();},save:()=>saves++});return Promise.resolve([]);}};
 window.eval(fs.readFileSync(require('node:path').join(__dirname,'../public/assets/document-editor.js'),'utf8'));
 const source=window.document.querySelector('select');
 assert.equal(config.license_key,'gpl'); assert.equal(config.language,'de');
 assert.equal(config.language_url,'https://local.test/assets/vendor/tinymce/langs/de.js');
 assert.equal(config.base_url,'https://local.test/assets/vendor/tinymce');assert.equal(config.suffix,'.min');
 assert.equal(config.toolbar_mode,'wrap');assert.match(config.toolbar,/bold italic underline/);
 assert.equal(window.document.querySelector('[name="receipt"]').disabled,true);
 source.value='pdf';source.dispatchEvent(new window.Event('change'));
 assert.equal(window.document.querySelector('[data-document-editor]').hidden,true);
 assert.equal(window.document.querySelector('[name="receipt"]').disabled,false);
 source.value='docx';source.dispatchEvent(new window.Event('change'));
 assert.equal(window.document.querySelector('[data-document-pdf]').hidden,false);
 assert.match(window.document.querySelector('[name="receipt"]').accept,/\.docx/);
 window.document.querySelector('form').dispatchEvent(new window.Event('submit'));
 assert.equal(saves,1);
});
test('missing editor assets show an explicit loading error',()=>{
 const dom=new JSDOM('<form data-document-form><select data-document-source><option value="editor">Editor</option></select><div data-document-editor><p data-document-editor-status data-error="Editor fehlt">Lädt</p><textarea data-document-content></textarea></div><div data-document-pdf><input name="receipt"></div></form>',{runScripts:'outside-only'});
 dom.window.eval(fs.readFileSync(require('node:path').join(__dirname,'../public/assets/document-editor.js'),'utf8'));
 assert.equal(dom.window.document.querySelector('[data-document-editor-status]').textContent,'Editor fehlt');
});
