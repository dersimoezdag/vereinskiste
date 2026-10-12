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

// Einrichtung als Schritt-fuer-Schritt-Assistent (ohne JavaScript bleibt das Formular komplett sichtbar).
document.querySelectorAll('form[data-stepper]').forEach(function (form) {
    var panels = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
    var labels = Array.prototype.slice.call(form.querySelectorAll('[data-step-label]'));
    var prev = form.querySelector('[data-step-prev]');
    var next = form.querySelector('[data-step-next]');
    var submit = form.querySelector('[data-step-submit]');
    var checksOk = form.dataset.checksOk === '1';
    var current = 0;

    function show(index) {
        current = index;
        form.classList.add('is-stepper');
        panels.forEach(function (panel, i) { panel.hidden = i !== index; });
        labels.forEach(function (label, i) {
            label.classList.toggle('is-active', i === index);
            label.classList.toggle('is-done', i < index);
            if (i === index) { label.setAttribute('aria-current', 'step'); } else { label.removeAttribute('aria-current'); }
        });
        var last = index === panels.length - 1;
        prev.hidden = index === 0;
        next.hidden = last;
        next.disabled = index === 0 && !checksOk;
        submit.hidden = !last;
        var focus = panels[index].querySelector('input, select');
        if (focus && index > 0) { focus.focus(); }
    }

    function valid(index) {
        return Array.prototype.every.call(panels[index].querySelectorAll('input, select'), function (field) {
            return field.reportValidity();
        });
    }

    prev.addEventListener('click', function () { show(current - 1); });
    next.addEventListener('click', function () { if (valid(current)) { show(current + 1); } });
    form.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && event.target.tagName === 'INPUT' && current < panels.length - 1) {
            event.preventDefault();
            next.click();
        }
    });
    show(0);
});
