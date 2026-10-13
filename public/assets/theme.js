// Setzt das Farbschema, bevor die Seite gezeichnet wird (wird synchron im <head> geladen).
// Ein Inline-Skript ginge nicht: die Content-Security-Policy erlaubt nur Skripte von der eigenen Domain.
// Ohne diese frueh geladene Datei erschiene bei jeder Navigation kurz das helle Schema.
(function () {
    var theme = null;
    try { theme = localStorage.getItem('vk-theme'); } catch (e) {}
    if (theme !== 'dark' && theme !== 'light') {
        theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-bs-theme', theme);
})();
