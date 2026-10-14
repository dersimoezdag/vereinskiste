// Theme vor dem Zeichnen setzen; Klick-Verarbeitung unabhaengig vom allgemeinen Seiten-JavaScript.
(function () {
    'use strict';
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    function stored() {
        try {
            var value = window.localStorage.getItem('vk-theme');
            return value === 'light' || value === 'dark' ? value : null;
        } catch (error) { return null; }
    }
    var choice = stored();
    function apply() {
        var theme = choice || (media && media.matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', theme);
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            var label = theme === 'dark' ? button.dataset.labelToLight : button.dataset.labelToDark;
            button.setAttribute('aria-pressed', String(theme === 'dark'));
            if (label) { button.setAttribute('aria-label', label); button.title = label; }
        });
    }
    apply();
    // Wird im Head geladen: delegierter Listener funktioniert auch fuer spaeter gerenderte Schalter.
    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target.closest('[data-theme-toggle]') : null;
        if (!target) { return; }
        choice = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        try { window.localStorage.setItem('vk-theme', choice); } catch (error) {}
        apply();
    });
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', apply); }
    if (media && media.addEventListener) { media.addEventListener('change', apply); }
    window.addEventListener('storage', function (event) {
        if (event.key === 'vk-theme' || event.key === null) { choice = stored(); apply(); }
    });
})();
