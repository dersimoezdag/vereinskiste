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

// Dark-Mode Umschaltung & Synchronisation
(function () {
    function updateThemeUI(theme) {
        document.querySelectorAll('.theme-toggle').forEach(function (btn) {
            var label = theme === 'dark' ? 'Zum hellen Modus wechseln' : 'Zum dunklen Modus wechseln';
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
        });
    }

    function getPreferredTheme() {
        var stored = null;
        try { stored = localStorage.getItem('vk-theme'); } catch (e) {}
        if (stored === 'dark' || stored === 'light') { return stored; }
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    var activeTheme = getPreferredTheme();
    document.documentElement.setAttribute('data-bs-theme', activeTheme);
    updateThemeUI(activeTheme);

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('.theme-toggle');
        if (!toggle) { return; }
        var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        try { localStorage.setItem('vk-theme', next); } catch (e) {}
        updateThemeUI(next);
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
                remove.textContent = zone.dataset.labelRemove || 'Entfernen';
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
            copy.textContent = copy.dataset.copiedLabel || 'Kopiert!';
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
