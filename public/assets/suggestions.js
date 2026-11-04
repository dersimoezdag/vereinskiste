/* Shared keyboard-accessible suggestions for editable text fields. */
(function () {
    'use strict';
    var sequence = 0;
    window.vkSuggestions = function (field) {
        var host = document.createElement('div');
        host.className = 'suggestions';
        field.before(host); host.append(field);
        var list = document.createElement('div'), values = [], active = -1;
        list.id = 'suggestions-' + (++sequence); list.className = 'suggestions-list';
        list.setAttribute('role', 'listbox'); host.append(list);
        field.setAttribute('role', 'combobox'); field.setAttribute('aria-autocomplete', 'list');
        field.setAttribute('aria-controls', list.id); field.autocomplete = 'off';
        function close() { list.hidden = true; field.setAttribute('aria-expanded', 'false'); field.removeAttribute('aria-activedescendant'); active = -1; }
        function open() { list.hidden = !values.length; field.setAttribute('aria-expanded', String(!!values.length)); }
        function select(index) {
            field.value = values[index]; close();
            field.dispatchEvent(new Event('input', {bubbles: true}));
            field.dispatchEvent(new Event('change', {bubbles: true})); close();
        }
        field.addEventListener('focus', open);
        field.addEventListener('blur', close);
        list.addEventListener('mousedown', function (event) { event.preventDefault(); });
        field.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { close(); return; }
            if (event.key === 'Enter' && !list.hidden && active >= 0) { event.preventDefault(); select(active); return; }
            if (!values.length || !['ArrowDown', 'ArrowUp'].includes(event.key)) { return; }
            event.preventDefault(); open();
            active = (active + (event.key === 'ArrowDown' ? 1 : -1) + values.length) % values.length;
            Array.from(list.children).forEach(function (item, index) { item.setAttribute('aria-selected', String(index === active)); });
            field.setAttribute('aria-activedescendant', list.children[active].id);
            if (list.children[active].scrollIntoView) { list.children[active].scrollIntoView({block: 'nearest'}); }
        });
        close();
        return {set: function (items) {
            values = items; active = -1; list.replaceChildren(); field.removeAttribute('aria-activedescendant');
            values.forEach(function (value, index) {
                var item = document.createElement('div'); item.id = list.id + '-' + index;
                item.setAttribute('role', 'option'); item.setAttribute('aria-selected', 'false'); item.textContent = value;
                item.addEventListener('click', function () { select(index); }); list.append(item);
            });
            if (document.activeElement === field) { open(); } else { close(); }
        }};
    };
})();
