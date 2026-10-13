const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const {JSDOM}=require('jsdom');
const script=fs.readFileSync(__dirname+'/../public/assets/invoice-ocr.js','utf8');
function setup() {
 const dom=new JSDOM(`<input name="_csrf" value="token"><div><button data-ocr-document data-document-url="/documents/1" data-save-url="/invoices/1/ocr/1">OCR</button><span data-ocr-status></span></div>`,{url:'https://verein.example.org/invoices/1',runScripts:'outside-only'});
 const {window}=dom;window.AbortController=AbortController;window.FormData=FormData;
 window.createImageBitmap=async()=>({width:100,height:100,close(){}});
 window.HTMLCanvasElement.prototype.getContext=()=>({drawImage(){}});
 return {dom,window,document:window.document};
}
test('OCR needs explicit click, uses same-origin assets, and only saves suggestions with CSRF',async()=>{
 const {window,document}=setup();let terminated=false;const calls=[];let options;
 window.Tesseract={createWorker:async(lang,mode,opts)=>{options=opts;return {recognize:async()=>({data:{text:'Gesamtbetrag: 12,50 EUR'}}),terminate:async()=>{terminated=true;}};}};
 window.fetch=async(url,opts)=>{calls.push({url,opts});return url==='/documents/1'?{ok:true,blob:async()=>new Blob(['x'],{type:'image/png'})}:{ok:true};};
 require('./browser-texts.cjs')(window); window.eval(script);assert.equal(calls.length,0);
 document.querySelector('button').click();await new Promise(r=>setTimeout(r,20));
 assert.equal(options.workerBlobURL,false);assert.equal(options.workerPath,'/assets/vendor/ocr/worker.min.js');assert.equal(options.langPath,'/assets/vendor/ocr/lang');
 assert.equal(calls.length,2);assert.equal(calls[1].opts.body.get('_csrf'),'token');assert.match(calls[1].opts.body.get('text'),/12,50/);
 assert.equal(terminated,true);assert.equal(document.querySelector('[data-ocr-status] a').textContent,'Vorschläge anzeigen');
 assert.equal(document.querySelector('button').disabled,false);window.close();
});
test('failed OCR terminates worker and leaves invoice fields unchanged',async()=>{
 const {window,document}=setup();let terminated=false;const calls=[];
 window.Tesseract={createWorker:async()=>({recognize:async()=>{throw Error('Nicht erkannt');},terminate:async()=>{terminated=true;}})};
 window.fetch=async(url)=>{calls.push(url);return {ok:true,blob:async()=>new Blob(['x'],{type:'image/png'})};};
 require('./browser-texts.cjs')(window); window.eval(script);document.querySelector('button').click();await new Promise(r=>setTimeout(r,20));
 assert.equal(calls.length,1);assert.equal(terminated,true);assert.equal(document.querySelector('[data-ocr-status]').textContent,'Nicht erkannt');window.close();
});
