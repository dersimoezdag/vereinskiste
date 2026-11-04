const {test}=require('node:test');const assert=require('node:assert/strict');const path=require('node:path');
test('bundled OCR engine recognizes a receipt amount using local language data', {timeout:60000},async()=>{
 const {createCanvas,GlobalFonts}=require('@napi-rs/canvas');const {createWorker}=require('tesseract.js');
 // Use the pinned project font so the fixture does not depend on runner system fonts.
 assert.ok(GlobalFonts.registerFromPath(path.join(__dirname,'node_modules/@fontsource-variable/geist/files/geist-latin-wght-normal.woff2'),'ReceiptTest'));
 const canvas=createCanvas(800,180),ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,800,180);ctx.fillStyle='#000';ctx.font='48px ReceiptTest';ctx.fillText('Total: 12.50 EUR',30,90);
 const worker=await createWorker('eng',1,{langPath:path.join(__dirname,'node_modules/@tesseract.js-data/eng/4.0.0_best_int'),cacheMethod:'none'});
 try {const result=await worker.recognize(canvas.toBuffer('image/png'));assert.match(result.data.text,/12[.,]50/);} finally {await worker.terminate();}
});
