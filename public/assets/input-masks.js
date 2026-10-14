/* Shared progressive input masks. Invalid input is retained for correction.
 * Dates use the browser's native date mask; international phone/postal formats stay flexible. */
(function (root) {
    'use strict';
    function money(value, signed) {
        var raw = value.replace(/[ \u00a0]/g, '');
        var sign = signed && raw[0] === '-' ? '-' : '';
        if (sign) { raw = raw.slice(1); }
        var match = raw.includes(',')
            ? /^(\d{1,3}(?:\.\d{3})*|\d+),(\d{1,2})$/.exec(raw)
            : /^(\d+)(?:\.(\d{1,2}))?$/.exec(raw);
        if (!match) { return null; }
        var integer = match[1].replace(/\./g, '').replace(/^0+(?=\d)/, '');
        if (integer.length > 7) { return null; }
        var fraction = (match[2] || '').padEnd(2, '0');
        if (!signed && integer === '0' && fraction === '00') { return null; }
        return (integer === '0' && fraction === '00' ? '' : sign) + integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + fraction;
    }
    // Telefon: 0 bzw. 00 wird zu +49 bzw. +, danach Ziffern in Gruppen; null bei unplausibler Laenge.
    function phone(value) {
        var raw = value.trim().replace(/\(0\)/g, '').replace(/[^\d+]/g, '');
        if (raw.indexOf('+') > 0 || raw.lastIndexOf('+') > 0) { return null; }
        var digits = raw.replace(/\+/g, '');
        if (raw[0] === '+') {
            digits = digits.replace(/^0+/, '');
        } else if (digits.slice(0, 2) === '00') {
            digits = digits.slice(2);
        } else if (digits[0] === '0') {
            digits = '49' + digits.slice(1);
        } else {
            return null;
        }
        if (digits.length < 8 || digits.length > 15) { return null; }
        var country = digits.slice(0, 2) === '49' ? '49' : digits.slice(0, digits[0] === '1' || digits[0] === '7' ? 1 : 2);
        var rest = digits.slice(country.length);
        // Vorwahllaengen sind nicht ableitbar; nur deutsche Mobilnetze (15x-17x) haben feste 3 Stellen.
        var mobile = country === '49' && /^1[5-7]/.test(rest);
        return '+' + country + ' ' + (mobile ? rest.slice(0, 3) + ' ' + rest.slice(3) : rest);
    }
    function compact(value) { return value.replace(/\s/g, '').toUpperCase(); }
    function validIban(value) {
        var iban = compact(value);
        if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(iban)) { return false; }
        var lengths = {DE:22, AT:20, CH:21, BE:16, NL:18, FR:27, GB:22, IE:22, ES:24, IT:27, LU:20};
        if (lengths[iban.slice(0, 2)] && iban.length !== lengths[iban.slice(0, 2)]) { return false; }
        var remainder = 0;
        (iban.slice(4) + iban.slice(0, 4)).split('').forEach(function (character) {
            var digits = /[A-Z]/.test(character) ? String(character.charCodeAt(0) - 55) : character;
            digits.split('').forEach(function (digit) { remainder = (remainder * 10 + Number(digit)) % 97; });
        });
        return remainder === 1;
    }
    function germanDate(iso) {
        return /^\d{4}-\d{2}-\d{2}$/.test(iso) ? iso.slice(8) + '.' + iso.slice(5, 7) + '.' + iso.slice(0, 4) : iso;
    }
    function isoDate(value) {
        var match = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(value.trim());
        if (!match || match[3] === '0000') { return null; }
        var iso = match[3] + '-' + match[2] + '-' + match[1];
        var date = new Date(iso + 'T00:00:00Z');
        return !isNaN(date.getTime()) && date.toISOString().slice(0, 10) === iso ? iso : null;
    }
    var api = {phone: phone, money: money, compact: compact, validIban: validIban, germanDate: germanDate, isoDate: isoDate};
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; }
    if (!root.document) { return; }
    var document = root.document;
    var specs = {
        date: {hint: window.vkText('js.datum_im_format_tt_mm_jjjj'), error: window.vkText('js.bitte_ein_gultiges_datum_im_format_tt_mm_jjjj_eingeben')},
        iban: {hint: window.vkText('js.iban_z_b_de89_3704_0044_0532_0130_00'), error: window.vkText('js.bitte_eine_gultige_iban_eingeben')},
        bic: {hint: window.vkText('js.bic_8_oder_11_zeichen'), error: window.vkText('js.bitte_einen_gultigen_bic_eingeben')},
        net: {hint: window.vkText('js.nettobetrag_z_b_1_000_00'), error: window.vkText('js.bitte_einen_betrag_ab_0_mit_hochstens_2_nachkommastellen_eingeben')},
        tax: {hint: window.vkText('js.steuerbetrag_z_b_190_00'), error: window.vkText('js.bitte_einen_betrag_ab_0_mit_hochstens_2_nachkommastellen_eingeben')},
        gross: {hint: window.vkText('js.bruttobetrag_z_b_1_190_00'), error: window.vkText('js.bitte_einen_betrag_groer_als_0_mit_hochstens_2_nachkommastellen_eingeben')},
        amount: {hint: window.vkText('js.betrag_z_b_1_234_56'), error: window.vkText('js.bitte_einen_betrag_groer_als_0_mit_hochstens_2_nachkommastellen_eingeben_ma')},
        opening_balance: {hint: window.vkText('js.betrag_z_b_123_45'), error: window.vkText('js.bitte_einen_betrag_mit_hochstens_2_nachkommastellen_eingeben_max_9_999_999_')},
        phone: {hint: window.vkText('js.telefonnummer_mit_vorwahl_z_b_49_30_123456')},
        email: {hint: window.vkText('js.e_mail_z_b_name_example_org')}
    };
    function kind(field) { return field.dataset.mask || (field.type === 'date' ? 'date' : '') || (specs[field.name] ? field.name : ''); }
    function update(field, format) {
        var type = kind(field), value = field.value, error = '', formatted = value;
        if (!specs[type]) { return; }
        if (value.trim() !== '') {
            if (type === 'date') {
                var iso = isoDate(value);
                error = iso === null ? specs.date.error : '';
                if (iso && ((field.dataset.dateMin && iso < field.dataset.dateMin) || (field.dataset.dateMax && iso > field.dataset.dateMax))) {
                    error = window.vkText('js.das_datum_liegt_auerhalb_des_erlaubten_zeitraums');
                }
                if (iso) { formatted = germanDate(iso); }
            } else if (type === 'iban') {
                error = validIban(value) ? '' : specs[type].error;
                formatted = compact(value).match(/.{1,4}/g).join(' ');
            } else if (type === 'bic') {
                formatted = compact(value);
                error = /^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(formatted) ? '' : specs[type].error;
            } else if (['amount', 'opening_balance', 'net', 'tax', 'gross'].includes(type)) {
                var result = money(value, ['opening_balance', 'net', 'tax'].includes(type));
                error = result === null || (['net', 'tax'].includes(type) && result[0] === '-') ? specs[type].error : '';
                if (result !== null) { formatted = result; }
            } else if (type === 'phone') {
                var number = phone(value);
                error = number === null ? window.vkText('js.bitte_eine_gultige_telefonnummer_eingeben') : '';
                if (number !== null) { formatted = number; }
            } else {
                formatted = value.trim();
            }
        }
        if (format) { field.value = formatted; }
        if (type === 'date') {
            var canonical = document.getElementById(field.dataset.dateCanonical);
            canonical.value = error || value.trim() === '' ? '' : isoDate(value);
        }
        field.setCustomValidity(error);
        if (field.dataset.maskFeedback) {
            var feedback = document.getElementById(field.dataset.maskFeedback);
            feedback.textContent = error || specs[type].hint;
            feedback.classList.toggle('text-danger', error !== '');
        }
    }
    var sequence = 0;
    function enhance(scope) {
        scope.querySelectorAll('input').forEach(function (field) {
            if (field.dataset.maskReady || field.type === 'hidden') { return; }
            var type = kind(field), spec = specs[type];
            if (!spec) { return; }
            field.dataset.maskReady = '1';
            if (type === 'date') {
                var initial = field.value;
                var canonical = document.createElement('input');
                canonical.type = 'hidden'; canonical.name = field.name; canonical.disabled = field.disabled;
                canonical.id = 'date-canonical-' + (++sequence);
                canonical.value = initial;
                if (field.hasAttribute('form')) { canonical.setAttribute('form', field.getAttribute('form')); }
                field.insertAdjacentElement('afterend', canonical);
                field.dataset.dateCanonical = canonical.id;
                field.dataset.mask = 'date';
                field.dataset.dateMin = field.min; field.dataset.dateMax = field.max;
                field.removeAttribute('name');
                field.type = 'text'; field.value = germanDate(initial); field.inputMode = 'numeric';
                field.maxLength = 10;
                var picker = document.createElement('input');
                picker.type = 'date'; picker.value = initial; picker.dataset.maskReady = 'picker';
                field.defaultValue = germanDate(initial); canonical.defaultValue = initial; picker.defaultValue = initial;
                picker.min = field.dataset.dateMin; picker.max = field.dataset.dateMax;
                picker.disabled = field.disabled; picker.readOnly = field.readOnly;
                picker.tabIndex = -1; picker.className = 'visually-hidden';
                picker.setAttribute('aria-label', window.vkText('js.datum_auswahlen'));
                field.insertAdjacentElement('afterend', picker);
                if (!field.disabled && !field.readOnly && typeof picker.showPicker === 'function') {
                    var button = document.createElement('button');
                    button.type = 'button'; button.className = 'btn btn-sm btn-outline-secondary mt-1';
                    button.textContent = window.vkText('js.datum_auswahlen');
                    field.insertAdjacentElement('afterend', button);
                    button.addEventListener('click', function () { picker.value = canonical.value; picker.showPicker(); });
                }
                picker.addEventListener('change', function () {
                    field.value = germanDate(picker.value); update(field, true);
                    field.dispatchEvent(new Event('change', {bubbles: true}));
                });
            }
            if (type === 'phone') { field.type = 'tel'; field.autocomplete = 'tel'; }
            if (type === 'email') { field.type = 'email'; }
            if (['amount', 'opening_balance', 'net', 'tax', 'gross'].includes(type)) { field.inputMode = 'decimal'; }
            if (type === 'iban') { field.maxLength = 42; field.spellcheck = false; }
            if (type === 'bic') { field.maxLength = 11; field.spellcheck = false; }
            if (!field.placeholder) { field.placeholder = spec.hint; }
            var feedback = document.createElement('div');
            feedback.id = 'input-mask-help-' + (++sequence);
            feedback.className = 'form-hint';
            feedback.textContent = spec.hint;
            field.insertAdjacentElement('afterend', feedback);
            field.dataset.maskFeedback = feedback.id;
            field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), feedback.id].filter(Boolean).join(' '));
            field.addEventListener('input', function () { if (!field.composing) { update(field, false); } });
            field.addEventListener('compositionstart', function () { field.composing = true; });
            field.addEventListener('compositionend', function () { field.composing = false; update(field, false); });
            field.addEventListener('blur', function () { update(field, true); });
            if (field.disabled || field.readOnly) { update(field, true); }
        });
    }
    enhance(document);
    // Delegated submit also covers forms that intentionally use novalidate for server errors.
    document.addEventListener('submit', function (event) {
        var invalid = null;
        event.target.querySelectorAll('input[data-mask-ready="1"]').forEach(function (field) {
            if (field.disabled || field.readOnly) { return; }
            update(field, true);
            if (!field.validity.valid && !invalid) { invalid = field; }
        });
        if (invalid) { event.preventDefault(); event.stopImmediatePropagation(); invalid.reportValidity(); }
    }, true);
    document.addEventListener('reset', function (event) {
        var form = event.target;
        root.setTimeout(function () {
            form.querySelectorAll('input[data-mask-ready="1"]').forEach(function (field) { update(field, false); });
        }, 0);
    });
    new MutationObserver(function () { enhance(document); }).observe(document.body, {childList: true, subtree: true});
})(typeof window === 'undefined' ? globalThis : window);
