// Kleiner WYSIWYG-Editor fuer Notizfelder (textarea[data-rich-text]). Ohne TinyMCE bleibt das Textfeld nutzbar.
(function () {
    'use strict';
    var fields = document.querySelectorAll('textarea[data-rich-text]');
    if (!fields.length || !window.tinymce) { return; }
    var script = document.querySelector('script[src*="vendor/tinymce/tinymce.min.js"]');
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    fields.forEach(function (field) {
        window.tinymce.init({
            base_url: new URL('.', script.src).href.replace(/\/$/, ''), suffix: '.min',
            target: field, license_key: 'gpl', language: 'de',
            language_url: new URL('langs/de.js', script.src).href,
            promotion: false, branding: false, height: 260, menubar: false, toolbar_mode: 'wrap',
            skin: dark ? 'oxide-dark' : 'oxide', content_css: dark ? 'dark' : 'default',
            plugins: 'autolink lists link',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | bullist numlist | link removeformat',
            block_formats: 'Absatz=p;Überschrift=h3;Unterüberschrift=h4',
            automatic_uploads: false, paste_data_images: false,
            setup: function (editor) {
                editor.on('change input undo redo', function () { editor.save(); });
                field.form.addEventListener('submit', function () { editor.save(); });
            }
        });
    });
})();
