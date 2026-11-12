/* Wiederverwendbare Komponente zur Personensuche im Backend:
   - Suchfeld zur Filterung ab 1 Buchstabe
   - Ohne Sucheingabe vollständige Liste aller Personen alphabetisch sortiert
   - Tastaturnavigation (Pfeiltasten, Enter, Escape, Tab) und WAI-ARIA Combobox-Standard
   - Automatischer Sync mit dem zugrundeliegenden Formularfeld
*/
(function () {
    'use strict';

    var collator = new Intl.Collator('de', {numeric: true, sensitivity: 'base'});
    var sequence = 0;

    function normalize(value) {
        return (value || '').toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    }

    function init(container) {
        if (!container || container.dataset.personPickerReady) { return; }
        container.dataset.personPickerReady = '1';

        var id = container.id || ('person-picker-' + (++sequence));
        var select = container.querySelector('[data-person-picker-select]') || container.querySelector('select');
        var control = container.querySelector('.person-picker-control');
        var searchInput = container.querySelector('[data-person-picker-search]');
        var clearBtn = container.querySelector('[data-person-picker-clear]');
        var list = container.querySelector('[data-person-picker-list]');

        var items = [];
        var emptyChoice = null;

        if (select) {
            Array.from(select.options).forEach(function (opt) {
                var val = String(opt.value != null ? opt.value : '');
                var text = opt.textContent.trim();
                var name = opt.dataset.memberName ? String(opt.dataset.memberName).trim() : text;
                var memberNo = opt.dataset.memberNo ? String(opt.dataset.memberNo).trim() : '';
                var email = opt.dataset.memberEmail ? String(opt.dataset.memberEmail).trim() : '';
                var item = {
                    id: val,
                    name: name,
                    memberNo: memberNo,
                    email: email,
                    label: text,
                    isEmpty: val === ''
                };
                if (item.isEmpty) {
                    emptyChoice = item;
                } else {
                    items.push(item);
                }
            });
            // Select verstecken, bleibt aber im Formular aktiv
            select.hidden = true;
            select.tabIndex = -1;
            select.setAttribute('aria-hidden', 'true');
        }

        // Personen immer alphabetisch nach Namen sortieren
        items.sort(function (a, b) {
            return collator.compare(a.name, b.name) || collator.compare(a.id, b.id);
        });

        // Falls die UI-Elemente noch nicht im DOM existieren (z. B. bei direkt aufgerufenem <select data-person-picker>)
        if (!control) {
            control = document.createElement('div');
            control.className = 'person-picker-control relative';

            var wrap = document.createElement('div');
            wrap.className = 'person-picker-input-wrap relative flex items-center';

            searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'input person-picker-search' + (select && select.classList.contains('is-invalid') ? ' is-invalid' : '');
            searchInput.id = id + '-search';
            searchInput.dataset.personPickerSearch = '1';
            searchInput.placeholder = container.dataset.placeholder || (window.vkText ? window.vkText('js.person_search_placeholder') : 'Person suchen …');
            searchInput.autocomplete = 'off';
            searchInput.setAttribute('role', 'combobox');
            searchInput.setAttribute('aria-autocomplete', 'list');
            searchInput.setAttribute('aria-expanded', 'false');
            searchInput.setAttribute('aria-haspopup', 'listbox');

            clearBtn = document.createElement('button');
            clearBtn.type = 'button';
            clearBtn.className = 'person-picker-clear';
            clearBtn.dataset.personPickerClear = '1';
            clearBtn.tabIndex = -1;
            var clearTitle = window.vkText ? window.vkText('js.person_search_clear') : 'Auswahl aufheben';
            clearBtn.title = clearTitle;
            clearBtn.setAttribute('aria-label', clearTitle);
            clearBtn.hidden = true;
            clearBtn.innerHTML = '<span aria-hidden="true">&times;</span>';

            wrap.appendChild(searchInput);
            wrap.appendChild(clearBtn);
            control.appendChild(wrap);

            list = document.createElement('div');
            list.className = 'suggestions-list person-picker-list';
            list.id = id + '-list';
            list.dataset.personPickerList = '1';
            list.setAttribute('role', 'listbox');
            list.hidden = true;
            control.appendChild(list);

            container.appendChild(control);
        }

        if (list && !list.id) { list.id = id + '-list'; }
        if (searchInput && list) { searchInput.setAttribute('aria-controls', list.id); }

        var isPickerDisabled = container.dataset.disabled === '1' || (select && select.disabled);
        if (isPickerDisabled && searchInput) {
            searchInput.disabled = true;
            if (clearBtn) { clearBtn.disabled = true; }
        }

        var activeIndex = -1;
        var currentRenderedItems = [];

        function findPersonById(val) {
            if (val === '' || val == null) { return null; }
            var strVal = String(val);
            for (var i = 0; i < items.length; i++) {
                if (items[i].id === strVal) { return items[i]; }
            }
            return null;
        }

        function syncDisplayFromValue() {
            var currentVal = select ? select.value : '';
            var current = findPersonById(currentVal);
            if (current) {
                searchInput.value = current.label || current.name;
                if (clearBtn) { clearBtn.hidden = searchInput.disabled; }
            } else {
                searchInput.value = '';
                if (clearBtn) { clearBtn.hidden = true; }
            }
        }

        syncDisplayFromValue();

        function getFiltered(query) {
            var q = normalize(query);
            // Sonst Liste alphabetisch!
            if (!q) {
                var full = items.slice();
                if (emptyChoice) {
                    full.unshift(emptyChoice);
                }
                return full;
            }

            // Ab 1 Buchstabe Vorschläge in Liste
            var terms = q.split(/\s+/).filter(Boolean);
            var matches = items.filter(function (p) {
                var searchTarget = normalize(p.name + ' ' + (p.memberNo ? ('#' + p.memberNo + ' ' + p.memberNo) : '') + ' ' + (p.email || ''));
                return terms.every(function (t) {
                    return searchTarget.includes(t);
                });
            });

            // Sortierung: Treffer, die mit dem Suchbegriff beginnen (Name oder Mitgliedsnummer), zuerst; sonst alphabetisch
            matches.sort(function (a, b) {
                var aStarts = normalize(a.name).startsWith(q) || (a.memberNo && a.memberNo.startsWith(q));
                var bStarts = normalize(b.name).startsWith(q) || (b.memberNo && b.memberNo.startsWith(q));
                if (aStarts && !bStarts) { return -1; }
                if (!aStarts && bStarts) { return 1; }
                return collator.compare(a.name, b.name);
            });

            if (emptyChoice && normalize(emptyChoice.label).includes(q)) {
                matches.unshift(emptyChoice);
            }
            return matches;
        }

        function open() {
            if (!list || searchInput.disabled) { return; }
            list.hidden = false;
            searchInput.setAttribute('aria-expanded', 'true');
        }

        function close() {
            if (!list) { return; }
            list.hidden = true;
            searchInput.setAttribute('aria-expanded', 'false');
            searchInput.removeAttribute('aria-activedescendant');
            activeIndex = -1;
        }

        function render(filtered) {
            if (!list) { return; }
            currentRenderedItems = filtered;
            list.replaceChildren();
            activeIndex = -1;
            searchInput.removeAttribute('aria-activedescendant');

            if (!filtered.length) {
                var noRes = document.createElement('div');
                noRes.className = 'person-picker-no-results text-muted p-2 text-sm';
                noRes.setAttribute('role', 'status');
                noRes.textContent = window.vkText ? window.vkText('js.person_search_no_results') : 'Keine Personen gefunden';
                list.appendChild(noRes);
                open();
                return;
            }

            filtered.forEach(function (item, idx) {
                var opt = document.createElement('div');
                opt.id = list.id + '-item-' + idx;
                opt.className = 'person-picker-option' + (item.isEmpty ? ' is-empty-choice' : '');
                opt.setAttribute('role', 'option');

                var isSelected = select && ((select.value === item.id) || (!select.value && item.isEmpty));
                opt.setAttribute('aria-selected', isSelected ? 'true' : 'false');

                var nameSpan = document.createElement('span');
                nameSpan.className = 'person-picker-name';
                nameSpan.textContent = item.label || item.name;
                opt.appendChild(nameSpan);

                if (item.memberNo && !item.isEmpty) {
                    var badge = document.createElement('span');
                    badge.className = 'badge text-muted person-picker-badge';
                    badge.textContent = '#' + item.memberNo;
                    opt.appendChild(badge);
                }

                opt.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // Verhindert Blur des Suchfelds vor Klick
                });
                opt.addEventListener('click', function () {
                    selectPerson(item);
                });

                list.appendChild(opt);
            });
            open();
        }

        function selectPerson(item) {
            var prevValue = select ? select.value : '';
            var newValue = item.isEmpty ? '' : item.id;

            if (select) {
                select.value = newValue;
            }

            if (item.isEmpty) {
                searchInput.value = '';
                if (clearBtn) { clearBtn.hidden = true; }
            } else {
                searchInput.value = item.label || item.name;
                if (clearBtn) { clearBtn.hidden = searchInput.disabled; }
            }

            close();

            if (prevValue !== newValue && select) {
                select.dispatchEvent(new Event('input', {bubbles: true}));
                select.dispatchEvent(new Event('change', {bubbles: true}));
                container.dispatchEvent(new CustomEvent('person-change', {
                    bubbles: true,
                    detail: {id: newValue, person: item}
                }));
            }
        }

        function updateActive(newIndex) {
            var options = Array.from(list.querySelectorAll('[role="option"]'));
            if (!options.length) { activeIndex = -1; return; }

            if (newIndex < 0) { newIndex = options.length - 1; }
            if (newIndex >= options.length) { newIndex = 0; }
            activeIndex = newIndex;

            options.forEach(function (opt, i) {
                var selected = (i === activeIndex);
                opt.setAttribute('aria-selected', selected ? 'true' : 'false');
                if (selected) {
                    searchInput.setAttribute('aria-activedescendant', opt.id);
                    if (opt.scrollIntoView) {
                        opt.scrollIntoView({block: 'nearest'});
                    }
                }
            });
        }

        // Ereignisse auf dem Suchfeld
        searchInput.addEventListener('focus', function () {
            // Beim Fokussieren vollständige Liste alphabetisch (oder nach aktuellem Begriff) anzeigen
            render(getFiltered(searchInput.value));
        });

        searchInput.addEventListener('input', function () {
            // ab 1 buchstabe vorschläge in liste, sonst liste alphabetisch
            render(getFiltered(searchInput.value));
            if (clearBtn) {
                clearBtn.hidden = searchInput.disabled || !searchInput.value;
            }
        });

        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (list.hidden) {
                    render(getFiltered(searchInput.value));
                } else {
                    updateActive(activeIndex + 1);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (list.hidden) {
                    render(getFiltered(searchInput.value));
                } else {
                    updateActive(activeIndex - 1);
                }
            } else if (e.key === 'Enter') {
                if (!list.hidden) {
                    if (activeIndex >= 0 && activeIndex < currentRenderedItems.length) {
                        e.preventDefault();
                        selectPerson(currentRenderedItems[activeIndex]);
                    } else if (currentRenderedItems.length === 1) {
                        e.preventDefault();
                        selectPerson(currentRenderedItems[0]);
                    }
                }
            } else if (e.key === 'Escape') {
                if (!list.hidden) {
                    e.preventDefault();
                    close();
                    syncDisplayFromValue();
                }
            } else if (e.key === 'Tab') {
                close();
                syncDisplayFromValue();
            }
        });

        searchInput.addEventListener('blur', function () {
            // Timeout gibt Mousedown-Events auf Optionen Zeit zum Feuern
            setTimeout(function () {
                close();
                syncDisplayFromValue();
            }, 180);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function (e) {
                e.preventDefault();
                selectPerson(emptyChoice || {id: '', name: '', label: '', isEmpty: true});
                render(getFiltered(''));
                searchInput.focus();
            });
        }

        // Klick außerhalb schließt die Liste
        document.addEventListener('click', function (e) {
            if (!container.contains(e.target)) {
                close();
            }
        });

        // Reagiert auf programmatische Änderungen am Select
        if (select) {
            select.addEventListener('change', function () {
                syncDisplayFromValue();
            });
        }
    }

    function enhanceSelect(select) {
        if (select.dataset.personPickerReady) { return; }
        var parentPicker = select.parentElement ? select.parentElement.closest('[data-person-picker]') : null;
        if (parentPicker && parentPicker !== select) { return; }
        select.dataset.personPickerReady = '1';
        var container = document.createElement('div');
        container.className = 'person-picker';
        container.dataset.personPicker = '1';
        select.before(container);
        container.appendChild(select);
        select.dataset.personPickerSelect = '1';
        init(container);
    }

    function scan() {
        if (typeof document === 'undefined' || !document || !document.querySelectorAll) { return; }
        document.querySelectorAll('[data-person-picker]').forEach(function (el) {
            if (el.tagName === 'SELECT') {
                enhanceSelect(el);
            } else {
                init(el);
            }
        });
        document.querySelectorAll('select[data-person-picker]').forEach(enhanceSelect);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }

    if (typeof MutationObserver !== 'undefined' && document.body) {
        new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
    }

    window.vkPersonPicker = {
        init: init,
        scan: scan
    };
})();
