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
    // Ungueltiges Feld in einem verborgenen Schritt: zu diesem Schritt wechseln, statt still abzubrechen.
    form.addEventListener('invalid', function (event) {
        var index = panels.indexOf(event.target.closest('[data-step]'));
        if (index !== -1 && index !== current) { show(index); }
    }, true);
    // Nach einem Serverfehler direkt zum letzten Schritt: die Eingaben sind erhalten, nur Passwoerter muessen neu eingegeben werden.
    show(form.hasAttribute('data-start-last') ? panels.length - 1 : 0);
});

// Explizite Darstellungsauswahl; System folgt auch späteren Betriebssystemänderungen.
(function () {
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var preference = 'system';
    function read() {
        var value;
        try { value = localStorage.getItem('vk-theme'); } catch (e) {}
        return value === 'light' || value === 'dark' ? value : 'system';
    }
    function apply() {
        document.documentElement.setAttribute('data-bs-theme', preference === 'system'
            ? (media && media.matches ? 'dark' : 'light') : preference);
        document.querySelectorAll('[data-theme-picker]').forEach(function (picker) {
            picker.querySelectorAll('[data-theme-choice]').forEach(function (input) { input.checked = input.value === preference; });
            picker.querySelectorAll('[data-theme-icon]').forEach(function (icon) { icon.hidden = icon.dataset.themeIcon !== preference; });
            var selected = picker.querySelector('[data-theme-choice][value="' + preference + '"]');
            var label = selected ? selected.parentNode.textContent.trim() : preference;
            var trigger = picker.querySelector('summary');
            trigger.setAttribute('aria-label', (picker.dataset.labelChoose || '') + ': ' + label);
            trigger.title = (picker.dataset.labelCurrent || '') + ': ' + label;
        });
    }
    preference = read();
    apply();
    document.querySelectorAll('[data-theme-picker]').forEach(function (picker) {
        picker.addEventListener('change', function (event) {
            if (!event.target.matches('[data-theme-choice]')) { return; }
            preference = ['light', 'dark'].indexOf(event.target.value) !== -1 ? event.target.value : 'system';
            try { localStorage.setItem('vk-theme', preference); } catch (e) {}
            apply();
            picker.open = false;
            picker.querySelector('summary').focus();
        });
        picker.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { picker.open = false; picker.querySelector('summary').focus(); }
        });
        document.addEventListener('click', function (event) { if (!picker.contains(event.target)) { picker.open = false; } });
    });
    if (media && media.addEventListener) { media.addEventListener('change', apply); }
    window.addEventListener('storage', function (event) {
        if (event.key === 'vk-theme' || event.key === null) { preference = read(); apply(); }
    });
})();

// Beleg-Dropzone: Dateien hineinziehen oder anklicken; alle gesammelten Dateien gehen mit dem Formular ab.
document.querySelectorAll('[data-dropzone]').forEach(function (zone) {
    var input = zone.querySelector('input[type="file"]');
    var list = zone.parentNode.querySelector('[data-dropzone-list]');
    if (!input || !list) {
        return;
    }
    var multiple = zone.dataset.multiple === '1';
    var canCollect = typeof DataTransfer !== 'undefined';
    var files = [];

    function sameFile(a, b) {
        return a.name === b.name && a.size === b.size && a.lastModified === b.lastModified;
    }

    function render() {
        list.textContent = '';
        files.forEach(function (file, index) {
            var item = document.createElement('li');
            item.className = 'dropzone-file';
            var name = document.createElement('span');
            name.className = 'dropzone-file-name';
            name.textContent = file.name + ' (' + Math.max(1, Math.round(file.size / 1024)) + ' KB)';
            item.appendChild(name);
            if (canCollect) {
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-sm btn-ghost-secondary';
                remove.textContent = zone.dataset.labelRemove || window.vkText('js.entfernen');
                remove.setAttribute('aria-label', remove.textContent + ': ' + file.name);
                remove.addEventListener('click', function () {
                    files.splice(index, 1);
                    sync();
                });
                item.appendChild(remove);
            }
            list.appendChild(item);
        });
    }

    function sync() {
        if (canCollect) {
            var transfer = new DataTransfer();
            files.forEach(function (file) { transfer.items.add(file); });
            input.files = transfer.files;
        }
        render();
    }

    function add(selected) {
        Array.prototype.forEach.call(selected, function (file) {
            if (!multiple) {
                files = [file];
            } else if (!files.some(function (known) { return sameFile(known, file); })) {
                files.push(file);
            }
        });
        sync();
    }

    input.addEventListener('change', function () {
        if (canCollect) {
            add(input.files);
        } else {
            files = Array.prototype.slice.call(input.files);
            render();
        }
    });

    ['dragenter', 'dragover'].forEach(function (type) {
        zone.addEventListener(type, function (event) {
            event.preventDefault();
            zone.classList.add('is-dragover');
        });
    });
    ['dragleave', 'dragend', 'drop'].forEach(function (type) {
        zone.addEventListener(type, function () { zone.classList.remove('is-dragover'); });
    });
    zone.addEventListener('drop', function (event) {
        event.preventDefault();
        if (canCollect && event.dataTransfer && event.dataTransfer.files.length) {
            add(event.dataTransfer.files);
        }
    });
});

// Ersatz fuer Inline-onclick (von der Content-Security-Policy blockiert): Link kopieren und Bereiche ein-/ausblenden.
document.addEventListener('click', function (event) {
    var copy = event.target.closest('[data-copy-path]');
    if (copy) {
        var url = window.location.origin + copy.dataset.copyPath;
        var original = copy.textContent;
        var done = function () {
            copy.textContent = copy.dataset.copiedLabel || window.vkText('js.kopiert');
            window.setTimeout(function () { copy.textContent = original; }, 2000);
        };
        var fallback = function () {
            var area = document.createElement('textarea');
            area.value = url;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { if (document.execCommand('copy')) { done(); } } catch (e) {}
            document.body.removeChild(area);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(done, fallback);
        } else {
            fallback();
        }
        return;
    }
    var toggle = event.target.closest('[data-toggle-target]');
    if (toggle) {
        var target = document.getElementById(toggle.dataset.toggleTarget);
        if (target) {
            var hidden = target.classList.toggle('d-none');
            toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
        }
    }
});

// Bootstrap Tooltips (z.B. fuer Info-Buttons/Hilfetexte)
(function () {
    function initTooltips(root) {
        var scope = root || document;
        if (window.bootstrap && typeof window.bootstrap.Tooltip === 'function') {
            scope.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
                if (!window.bootstrap.Tooltip.getInstance(el)) {
                    new window.bootstrap.Tooltip(el, {
                        delay: { show: 150, hide: 100 }
                    });
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initTooltips(); });
    } else {
        initTooltips();
    }

    // Klick auf Hilfs-Tooltip fängt das Label-Event ab und toggelt den Tooltip auch bei Touch-Gesten
    document.addEventListener('click', function (event) {
        var tip = event.target.closest('.help-tooltip-btn');
        if (tip) {
            event.preventDefault();
            event.stopPropagation();
            if (window.bootstrap && typeof window.bootstrap.Tooltip === 'function') {
                var inst = window.bootstrap.Tooltip.getInstance(tip) || new window.bootstrap.Tooltip(tip);
                if (inst) {
                    if (tip.getAttribute('aria-describedby')) {
                        inst.hide();
                    } else {
                        inst.show();
                    }
                }
            }
        }
    });
})();


