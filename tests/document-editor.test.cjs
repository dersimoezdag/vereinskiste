const test=require('node:test'), assert=require('node:assert/strict'), fs=require('node:fs');
const {JSDOM}=require('jsdom');
test('editor and PDF modes preserve text and submit the latest editor content',()=>{
 const dom=new JSDOM('<form data-document-form><select data-document-source><option value="editor">Editor</option><option value="pdf">PDF</option></select><div data-document-editor><textarea data-document-content></textarea></div><div data-document-pdf><input name="receipt" type="file"></div></form><script src="https://local.test/assets/vendor/tinymce/tinymce.min.js"></script>',{url:'https://local.test/documents/minutes/new',runScripts:'outside-only'});
 const {window}=dom; let config,saves=0;
 window.tinymce={init:c=>{config=c;c.setup({on:()=>{},save:()=>saves++});}};
 window.eval(fs.readFileSync(require('node:path').join(__dirname,'../public/assets/document-editor.js'),'utf8'));
 const source=window.document.querySelector('select');
 assert.equal(config.license_key,'gpl'); assert.equal(config.language,'de');
 assert.equal(config.language_url,'https://local.test/assets/vendor/tinymce/langs/de.js');
 assert.equal(window.document.querySelector('[name="receipt"]').disabled,true);
 source.value='pdf';source.dispatchEvent(new window.Event('change'));
 assert.equal(window.document.querySelector('[data-document-editor]').hidden,true);
 assert.equal(window.document.querySelector('[name="receipt"]').disabled,false);
 window.document.querySelector('form').dispatchEvent(new window.Event('submit'));
 assert.equal(saves,1);
});
