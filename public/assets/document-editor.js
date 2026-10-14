(function () {
    'use strict';
    var form = document.querySelector('[data-document-form]');
    if (!form) { return; }
    var source = form.querySelector('[data-document-source]');
    var textarea = form.querySelector('[data-document-content]');
    function sync() {
        form.querySelector('[data-document-editor]').hidden = source.value !== 'editor';
        form.querySelector('[data-document-pdf]').hidden = source.value === 'editor';
        form.querySelector('[name="receipt"]').disabled = source.value === 'editor';
        form.querySelector('[name="receipt"]').accept = source.value === 'docx' ? '.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document' : '.pdf,application/pdf';
    }
    source.addEventListener('change', sync); sync();
    var status = form.querySelector('[data-document-editor-status]');
    function failed() { if (status) { status.hidden = false; status.textContent = status.dataset.error; } }
    if (!window.tinymce) { failed(); return; }
    var loadingTimer = window.setTimeout(failed, 15000);
    var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    window.tinymce.init({
        base_url: new URL('.', document.querySelector('script[src*="vendor/tinymce/tinymce.min.js"]').src).href.replace(/\/$/, ''), suffix: '.min',
        target: textarea, license_key: 'gpl', language: 'de',
        language_url: new URL('langs/de.js', document.querySelector('script[src*="vendor/tinymce/tinymce.min.js"]').src).href,
        promotion: false, branding: true, height: 560,
        toolbar_mode: 'wrap',
        skin: dark ? 'oxide-dark' : 'oxide', content_css: dark ? 'dark' : 'default',
        plugins: 'advlist autolink lists link charmap preview searchreplace visualblocks code fullscreen table nonbreaking wordcount insertdatetime pagebreak help',
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table link charmap | searchreplace removeformat | preview fullscreen code',
        pagebreak_separator: '<hr style="page-break-after:always">',
        menubar: 'edit view insert format tools table help',
        automatic_uploads: false, paste_data_images: false,
        setup: function (editor) {
            editor.on('init', function () { window.clearTimeout(loadingTimer); if (status) { status.hidden = true; } });
            editor.on('change input undo redo', function () { editor.save(); });
            form.addEventListener('submit', function () { editor.save(); });
        }
    }).catch(function () { window.clearTimeout(loadingTimer); failed(); });
})();
