// Package pinned browser assets from npm. Node is used only during builds/tests.
const fs=require('node:fs');const path=require('node:path');
const root=path.resolve(__dirname,'..'), modules=path.join(root,'tests/node_modules'), target=path.join(root,'public/assets/vendor/ocr');
fs.mkdirSync(target,{recursive:true});
function copy(source,destination) {const out=path.join(target,destination);fs.mkdirSync(path.dirname(out),{recursive:true});fs.copyFileSync(path.join(modules,source),out);if(fs.statSync(out).size>8388608)throw Error('Asset exceeds updater file limit: '+destination);}
copy('tesseract.js/dist/tesseract.min.js','tesseract.min.js');copy('tesseract.js/dist/worker.min.js','worker.min.js');
for(const prefix of ['tesseract-core-lstm','tesseract-core-simd-lstm','tesseract-core-relaxedsimd-lstm']) {
    for(const suffix of ['.wasm.js','.wasm'])copy('tesseract.js-core/'+prefix+suffix,'core/'+prefix+suffix);
}
for(const lang of ['deu','eng'])copy('@tesseract.js-data/'+lang+'/4.0.0_best_int/'+lang+'.traineddata.gz','lang/'+lang+'.traineddata.gz');
copy('pdfjs-dist/build/pdf.min.mjs','pdf.min.mjs');copy('pdfjs-dist/build/pdf.worker.min.mjs','pdf.worker.min.mjs');
copy('tesseract.js/LICENSE.md','LICENSE-tesseract.txt');copy('tesseract.js-core/LICENSE','LICENSE-core.txt');copy('pdfjs-dist/LICENSE','LICENSE-pdfjs.txt');
fs.writeFileSync(path.join(target,'README.txt'),'Pinned dependencies and language data: see tests/package-lock.json. Tesseract, PDF.js and Tesseract language data are Apache-2.0 licensed. All processing assets are served by this installation; no CDN is used.\n');
console.log('Browser OCR assets packaged.');
