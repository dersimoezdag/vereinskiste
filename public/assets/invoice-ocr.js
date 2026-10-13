// Local browser OCR. The server receives text suggestions only after an explicit click.
(function () {
    'use strict';
    var running = false;
    document.querySelectorAll('[data-ocr-document]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (running) { return; }
            running = true;
            var status = button.parentNode.querySelector('[data-ocr-status]');
            var worker = null, task = null, timer = null, stopped = false, abort = new AbortController();
            function say(text) { status.textContent = text; }
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            var deadline = new Promise(function (_, reject) {
                timer = setTimeout(function () { stopped = true; abort.abort(); reject(new Error(window.vkText('js.texterkennung_dauert_zu_lange_bitte_den_beleg_manuell_erfassen'))); }, 60000);
            });
            async function getWorker() {
                if (stopped) { throw new Error(window.vkText('js.abgebrochen')); }
                if (!worker) {
                    var created = await Tesseract.createWorker('deu+eng', 1, {
                        workerPath: '/assets/vendor/ocr/worker.min.js', workerBlobURL: false,
                        corePath: '/assets/vendor/ocr/core', langPath: '/assets/vendor/ocr/lang',
                        cacheMethod: 'none',
                        logger: function (info) { if (!stopped && info.status === 'recognizing text') { say(window.vkText('js.text_wird_erkannt') + Math.round(info.progress * 100) + ' %'); } }
                    });
                    if (stopped) { await created.terminate(); throw new Error(window.vkText('js.abgebrochen')); }
                    worker = created;
                }
                return worker;
            }
            async function recognize(image) {
                var w = await getWorker();
                return (await w.recognize(image)).data.text;
            }
            async function perform() {
                say(window.vkText('js.beleg_wird_geladen'));
                var response = await fetch(button.dataset.documentUrl, { credentials: 'same-origin', signal: abort.signal });
                if (!response.ok) { throw new Error(window.vkText('js.beleg_ist_nicht_verfugbar')); }
                var blob = await response.blob();
                if (blob.size > 10485760) { throw new Error(window.vkText('js.beleg_ist_zu_gro')); }
                var text = '';
                if (blob.type === 'application/pdf') {
                    say(window.vkText('js.pdf_wird_im_browser_gelesen'));
                    var pdfjs = await import('/assets/vendor/ocr/pdf.min.mjs');
                    pdfjs.GlobalWorkerOptions.workerSrc = '/assets/vendor/ocr/pdf.worker.min.mjs';
                    task = pdfjs.getDocument({ data: new Uint8Array(await blob.arrayBuffer()), isEvalSupported: false, useWasm: false, useSystemFonts: true, disableFontFace: true });
                    var pdf = await task.promise;
                    if (pdf.numPages > 5) { throw new Error(window.vkText('js.pdf_hat_mehr_als_funf_seiten_bitte_manuell_erfassen')); }
                    for (var pageNo = 1; pageNo <= pdf.numPages; pageNo++) {
                        if (stopped) { throw new Error(window.vkText('js.abgebrochen')); }
                        var page = await pdf.getPage(pageNo);
                        var nativeText = (await page.getTextContent()).items.map(function (item) { return item.str; }).join(' ');
                        if (nativeText.trim().length > 30) { text += nativeText + '\n'; continue; }
                        var base = page.getViewport({ scale: 1 });
                        var view = page.getViewport({ scale: Math.min(3, 2200 / Math.max(base.width, base.height)) });
                        var canvas = document.createElement('canvas');canvas.width = Math.ceil(view.width);canvas.height = Math.ceil(view.height);
                        await page.render({canvasContext: canvas.getContext('2d'),viewport: view}).promise;
                        say(window.vkText('js.seite') + pageNo + ' von ' + pdf.numPages + ' wird erkannt …');
                        text += await recognize(canvas);canvas.width = 0;canvas.height = 0;
                    }
                } else if (blob.type === 'image/jpeg' || blob.type === 'image/png') {
                    var bitmap = await createImageBitmap(blob);
                    try {
                        if (bitmap.width * bitmap.height > 40000000) { throw new Error(window.vkText('js.bild_ist_zu_gro')); }
                        var scale = Math.min(1, 2200 / Math.max(bitmap.width, bitmap.height));
                        var imageCanvas = document.createElement('canvas');imageCanvas.width = Math.max(1, Math.round(bitmap.width * scale));imageCanvas.height = Math.max(1, Math.round(bitmap.height * scale));
                        imageCanvas.getContext('2d').drawImage(bitmap, 0, 0, imageCanvas.width, imageCanvas.height);
                        text = await recognize(imageCanvas);imageCanvas.width = 0;imageCanvas.height = 0;
                    } finally { bitmap.close(); }
                } else { throw new Error(window.vkText('js.dieser_dateityp_wird_nicht_per_ocr_verarbeitet')); }
                if (stopped) { throw new Error(window.vkText('js.abgebrochen')); }
                if (!text.trim()) { throw new Error(window.vkText('js.kein_text_erkannt_bitte_manuell_erfassen')); }
                var body = new FormData();body.set('_csrf', document.querySelector('[name="_csrf"]').value);body.set('text',text.slice(0,20000));
                var saved = await fetch(button.dataset.saveUrl, {method:'POST',body:body,credentials:'same-origin',headers:{Accept:'application/json'},signal:abort.signal});
                if (!saved.ok) { throw new Error(window.vkText('js.ocr_ergebnis_konnte_nicht_gespeichert_werden_bitte_seite_neu_laden')); }
                say(window.vkText('js.text_erkannt_seite_neu_laden_um_die_vorschlage_zu_prufen'));
                var reload = document.createElement('a');reload.href = window.location.pathname;reload.textContent = window.vkText('js.vorschlage_anzeigen');reload.className = 'btn btn-sm mt-2';status.appendChild(document.createElement('br'));status.appendChild(reload);
            }
            try { await Promise.race([perform(), deadline]); }
            catch (error) { say(error.message || window.vkText('js.ocr_fehlgeschlagen_bitte_manuell_erfassen')); }
            finally {
                stopped = true;clearTimeout(timer);abort.abort();
                if (worker) { await worker.terminate(); }
                if (task) { await task.destroy(); }
                button.disabled = false;button.removeAttribute('aria-busy');running = false;
            }
        });
    });
})();
