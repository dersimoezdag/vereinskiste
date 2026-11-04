// Mitgliedsantrag als Stepper: ein Formular, Schritte werden nur ein- und ausgeblendet.
(function () {
    'use strict';
    function text(key, fallback) {
        var value = window.vkText ? window.vkText(key) : key;
        return value === key ? fallback : value;
    }

    function Stepper(form) {
        this.form = form;
        this.steps = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
        this.status = document.querySelector('[data-stepper-status]');
        this.bar = document.querySelector('[data-stepper-bar]');
        var self = this;
        form.addEventListener('click', function (e) {
            var t = e.target.closest('[data-step-next],[data-step-prev],[data-step-goto]');
            if (!t) { return; }
            e.preventDefault();
            if (t.hasAttribute('data-step-next')) { if (self.valid(self.index)) { self.show(self.index + 1, true); } }
            else if (t.hasAttribute('data-step-prev')) { self.show(self.index - 1, true); }
            else { self.show(parseInt(t.getAttribute('data-step-goto'), 10), true); }
        });
        form.addEventListener('submit', function (e) {
            for (var i = 0; i < self.steps.length; i++) {
                if (!self.valid(i)) { e.preventDefault(); self.show(i, true); self.valid(i); return; }
            }
        });
        form.addEventListener('signature:change', function (e) { e.target.classList.remove('is-invalid'); });
        window.addEventListener('popstate', function () { self.show(self.fromHash(), false); });
        var submit = form.querySelector('[data-stepper-submit]');
        if (submit) { submit.disabled = false; }
        var error = this.steps.findIndex(function (s) { return s.hasAttribute('data-step-error'); });
        this.index = -1;
        this.show(error >= 0 ? error : this.fromHash(), false, error < 0 && this.fromHash() === 0);
    }

    Stepper.prototype.fromHash = function () {
        var m = /^#schritt-(\d+)$/.exec(window.location.hash);
        var i = m ? parseInt(m[1], 10) - 1 : 0;
        return i >= 0 && i < this.steps.length ? i : 0;
    };

    Stepper.prototype.show = function (index, push, silent) {
        if (index < 0 || index >= this.steps.length) { return; }
        this.index = index;
        this.steps.forEach(function (s, i) { s.hidden = i !== index; });
        var n = index + 1, total = this.steps.length;
        if (this.status) { this.status.textContent = text('js.step_count', 'Schritt {current} von {total}').replace('{current}', n).replace('{total}', total); }
        if (this.bar) { this.bar.style.width = Math.round(n / total * 100) + '%'; }
        if (index === this.steps.length - 1) { this.summary(); }
        this.prefillPlaces(this.steps[index]);
        if (push && window.location.hash !== '#schritt-' + n) { window.history.pushState(null, '', '#schritt-' + n); }
        if (!silent) {
            var heading = this.steps[index].querySelector('h2');
            // Jeder Schrittwechsel beginnt oben auf der Seite (Fortschrittsanzeige sichtbar).
            if (heading) { heading.focus({preventScroll: true}); }
            window.scrollTo(0, 0);
        }
    };

    // Ort der Unterschrift folgt dem Wohnort, solange niemand ihn selbst geaendert hat.
    Stepper.prototype.prefillPlaces = function (step) {
        var city = this.form.querySelector('[name="city"]');
        if (!city) { return; }
        step.querySelectorAll('[data-signed-place]').forEach(function (field) {
            var auto = field.getAttribute('data-auto-place');
            if (field.value !== '' && field.value !== auto) { return; }
            field.value = city.value.trim();
            field.setAttribute('data-auto-place', field.value);
        });
    };

    Stepper.prototype.valid = function (index) {
        var step = this.steps[index], ok = true, first = null;
        step.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (el.type === 'hidden' || el.disabled || !el.willValidate) { return; }
            if (!el.checkValidity()) { ok = false; el.classList.add('is-invalid'); first = first || el; }
            else { el.classList.remove('is-invalid'); }
        });
        step.querySelectorAll('[data-signature-pad][data-signature-required]').forEach(function (pad) {
            var value = pad.querySelector('[data-signature-value]');
            if (!value || value.value === '') { ok = false; pad.classList.add('is-invalid'); first = first || pad.querySelector('canvas') || pad; }
        });
        if (first) {
            if (first.reportValidity) { first.reportValidity(); }
            if (first.focus) { first.focus(); }
        }
        return ok;
    };

    Stepper.prototype.summary = function () {
        var form = this.form;
        form.querySelectorAll('[data-summary-choice]').forEach(function (el) {
            var id = el.getAttribute('data-summary-choice');
            var checked = form.querySelector('[name="declaration[' + id + ']"]:checked');
            if (!checked) { return; }
            el.textContent = checked.type === 'checkbox'
                ? text('js.declaration_confirmed', 'Bestätigt')
                : (checked.closest('label') ? checked.closest('label').textContent.trim() : checked.value);
        });
        form.querySelectorAll('[data-summary-signature]').forEach(function (img) {
            var value = form.querySelector('[name="signature[' + img.getAttribute('data-summary-signature') + ']"]');
            if (value && value.value) { img.setAttribute('src', value.value); img.hidden = false; } else { img.hidden = true; }
        });
    };

    function init() {
        document.querySelectorAll('form[data-application-stepper]').forEach(function (form) {
            if (!form.vkStepper) { form.vkStepper = new Stepper(form); }
        });
    }
    window.VkApplicationStepper = {init: init};
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
