/* Antragsentscheidung: Zusatzfelder je Entscheidung, passende Mailvorlage und Live-Zusammenfassung. */
(function () {
    'use strict';
    var form = document.querySelector('form[data-application-decision]');
    if (!form) { return; }
    var subject = form.querySelector('[name="subject"]');
    var message = form.querySelector('[name="message"]');
    var notice = form.querySelector('[data-template-notice]');
    var summary = form.querySelector('[data-decision-summary]');
    var submit = form.querySelector('[data-step-submit]');
    var current = decision();

    function decision() {
        var checked = form.querySelector('[name="decision"]:checked');
        return checked ? checked.value : 'approved';
    }
    function template(kind) {
        return {subject: form.dataset['template' + cap(kind) + 'Subject'] || '', message: form.dataset['template' + cap(kind) + 'Message'] || ''};
    }
    function cap(text) { return text.charAt(0).toUpperCase() + text.slice(1); }
    // Unveraendert heisst: entspricht noch der Vorlage der bisherigen Entscheidung oder ist leer.
    function untouched(kind) {
        var t = template(kind);
        return (subject.value === t.subject || subject.value.trim() === '') && (message.value === t.message || message.value.trim() === '');
    }
    function applyTemplate(kind) {
        var t = template(kind);
        subject.value = t.subject;
        message.value = t.message;
        notice.hidden = true;
    }
    function toggleFields(kind) {
        form.querySelectorAll('[data-decision-fields]').forEach(function (box) {
            var on = box.dataset.decisionFields === kind;
            box.hidden = !on;
            // Auch die von der Datumsmaske erzeugten Felder (verstecktes ISO-Feld, Picker) mit abschalten.
            box.querySelectorAll('input, select, textarea').forEach(function (field) { field.disabled = !on; });
        });
    }
    function germanDate(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
    }
    function refresh() {
        var kind = decision();
        var joinDate = form.querySelector('input[type="hidden"][name="join_date"], input[type="date"][name="join_date"]');
        var type = form.querySelector('[name="membership_type_id"]');
        var text = (summary.dataset['text' + cap(kind)] || '')
            .replace('{name}', summary.dataset.name)
            .replace('{email}', summary.dataset.email)
            .replace('{date}', germanDate(joinDate ? joinDate.value : '') || '–')
            .replace('{type}', type && type.selectedIndex >= 0 ? type.options[type.selectedIndex].text : '–');
        summary.querySelector('[data-summary-text]').textContent = text;
        summary.classList.toggle('is-approved', kind === 'approved');
        summary.classList.toggle('is-rejected', kind === 'rejected');
        submit.classList.toggle('btn-success', kind === 'approved');
        submit.classList.toggle('btn-danger', kind === 'rejected');
        submit.querySelector('[data-submit-label]').textContent = submit.dataset['label' + cap(kind)];
    }

    form.addEventListener('change', function (event) {
        if (event.target.name === 'decision') {
            var next = decision();
            if (next !== current) {
                toggleFields(next);
                if (untouched(current)) { applyTemplate(next); } else { notice.hidden = false; }
                current = next;
            }
        }
        refresh();
    });
    form.addEventListener('input', refresh);
    notice.querySelector('[data-template-apply]').addEventListener('click', function () { applyTemplate(current); });
    // Die Datumsmaske setzt das ISO-Feld erst nach ihrem Aufbau; danach einmal aktualisieren.
    window.setTimeout(refresh, 0);
    refresh();
}());
