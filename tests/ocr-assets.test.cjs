const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');const {execFileSync}=require('node:child_process');
test('release assets include local workers, language data, SIMD variants and a restricted worker CSP',()=>{
 execFileSync(process.execPath,[path.join(__dirname,'../scripts/vendor-browser-ocr.cjs')]);
 const base=path.join(__dirname,'../public/assets/vendor/ocr');
 for(const file of ['tesseract.min.js','worker.min.js','pdf.min.mjs','pdf.worker.min.mjs','lang/deu.traineddata.gz','lang/eng.traineddata.gz','core/tesseract-core-lstm.wasm.js','core/tesseract-core-simd-lstm.wasm.js','core/tesseract-core-relaxedsimd-lstm.wasm.js']) {
  assert.ok(fs.statSync(path.join(base,file)).size>0);assert.ok(fs.statSync(path.join(base,file)).size<=8388608);
 }
 const policy=fs.readFileSync(path.join(base,'.htaccess'),'utf8');assert.match(policy,/connect-src 'self'/);assert.match(policy,/wasm-unsafe-eval/);assert.doesNotMatch(policy,/'unsafe-eval'/);
});
