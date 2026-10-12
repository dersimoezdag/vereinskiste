// Eigenes JavaScript der Vereinskiste (bewusst winzig).
// Die einklappbare Navigation uebernimmt Tabler (data-bs-toggle="collapse").
// Hier nur: doppeltes Absenden eines Formulars verhindern.
document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = '1';
    window.setTimeout(function () {
        delete form.dataset.submitting;
    }, 4000);
});
