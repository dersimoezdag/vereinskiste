// Pointer Events unterstützen Maus und Touch; die Pfeile bleiben per Tastatur bedienbar.
(function () {
    'use strict';
    document.querySelectorAll('[data-dashboard-form]').forEach(function (form) {
        form.classList.add('dashboard-ready');
        var status = form.querySelector('[data-dashboard-status]');
        var editable = form.hasAttribute('data-dashboard-editable');
        var editing = !editable, saving = false, drag = null;
        function setEditing(value) {
            editing = value;
            form.classList.toggle('dashboard-editing', value);
            var button = form.querySelector('[data-dashboard-edit]');
            if (button) { button.setAttribute('aria-pressed', String(value)); button.disabled = value; }
        }
        setEditing(editing);
        function sync(list) {
            positions(list);
            var group = list.dataset.dashboardGroup;
            if (!group) { return; }
            var ids = Array.from(list.children).map(function (item) { return item.dataset.dashboardId; });
            var empty = form.querySelector('[data-dashboard-empty="' + group + '"]');
            if (empty) { empty.hidden = ids.length > 0; }
            form.querySelectorAll('[data-dashboard-add][data-dashboard-group="' + group + '"]').forEach(function (button) {
                button.hidden = ids.includes(button.dataset.dashboardAdd);
            });
        }
        function message(text) { if (status) { status.textContent = text; } }
        function positions(list) {
            Array.from(list.children).forEach(function (item, index) {
                var input = item.querySelector('[data-position]');
                if (input) { input.value = index + 1; }
                var up = item.querySelector('[data-move="up"]');
                var down = item.querySelector('[data-move="down"]');
                if (up) { up.disabled = index === 0; }
                if (down) { down.disabled = index === list.children.length - 1; }
            });
        }
        async function save() {
            if (!editing || saving || drag) { return; }
            saving = true;
            form.classList.add('dashboard-saving');
            form.querySelector('[data-layout-save]').disabled = true;
            message(window.vkText('js.dashboard_saving'));
            try {
                var response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (response.redirected) { throw new Error(window.vkText('js.speichern_fehlgeschlagen_bitte_neu_laden')); }
                var result = await response.json();
                if (!response.ok || !Number.isInteger(result.version)) { throw new Error(result.error || window.vkText('js.speichern_fehlgeschlagen_bitte_neu_laden_und_erneut_versuchen')); }
                form.querySelector('[name="version"]').value = result.version;
                setEditing(false);
                message(window.vkText('js.dashboard_saved'));
                form.querySelector('[data-dashboard-edit]').focus();
            } catch (error) {
                message(error.message || window.vkText('js.speichern_fehlgeschlagen_bitte_neu_laden'));
            } finally {
                saving = false;
                form.classList.remove('dashboard-saving');
                form.querySelector('[data-layout-save]').disabled = false;
            }
        }
        function changed(list) {
            sync(list);
            message(window.vkText('js.dashboard_changed'));
        }
        form.querySelectorAll('[data-dashboard-list]').forEach(sync);
        form.addEventListener('click', function (event) {
            if (event.target.closest('[data-dashboard-edit]') && !saving) { setEditing(true); return; }
            if (!editing || saving || drag) { return; }
            var add = event.target.closest('[data-dashboard-add]');
            if (add) {
                var group = add.dataset.dashboardGroup;
                var list = form.querySelector('[data-dashboard-list][data-dashboard-group="' + group + '"]');
                var template = form.querySelector('[data-dashboard-template="' + group + ':' + add.dataset.dashboardAdd + '"]');
                if (!list || !template || Array.from(list.children).some(function (item) { return item.dataset.dashboardId === add.dataset.dashboardAdd; })) { return; }
                list.appendChild(template.content.cloneNode(true));
                changed(list);
                list.lastElementChild.querySelector('[data-drag-handle]').focus();
                return;
            }
            var remove = event.target.closest('[data-dashboard-remove]');
            if (remove) {
                var item = remove.closest('[data-dashboard-item]'), list = item.parentNode;
                var id = item.dataset.dashboardId;
                item.remove();
                changed(list);
                form.querySelector('[data-dashboard-add="' + id + '"][data-dashboard-group="' + list.dataset.dashboardGroup + '"]').focus();
                return;
            }
            var button = event.target.closest('[data-move]');
            if (!button) { return; }
            var item = button.closest('[data-dashboard-item]'), list = item.parentNode;
            var target = button.dataset.move === 'up' ? item.previousElementSibling : item.nextElementSibling;
            if (!target || !target.hasAttribute('data-dashboard-item')) { return; }
            list.insertBefore(item, button.dataset.move === 'up' ? target : target.nextSibling);
            changed(list);
            // Auch am Rand bleibt der Griff erreichbar, statt Fokus auf disabled zu verlieren.
            (button.disabled ? item.querySelector('[data-drag-handle]') : button).focus();
        });
        function finish(cancel) {
            if (!drag) { return; }
            var state = drag;
            drag = null;
            state.item.classList.remove('dashboard-dragging');
            if (cancel) { state.order.forEach(function (item) { state.list.appendChild(item); }); positions(state.list); }
            else if (state.order.some(function (item, index) { return state.list.children[index] !== item; })) { changed(state.list); }
            if (state.handle.hasPointerCapture && state.handle.hasPointerCapture(state.pointerId)) { state.handle.releasePointerCapture(state.pointerId); }
            state.handle.focus();
        }
        form.addEventListener('pointerdown', function (event) {
            var handle = event.target.closest('[data-drag-handle]');
            if (!editing || saving || !handle || drag || event.button !== 0) { return; }
            var item = handle.closest('[data-dashboard-item]');
            drag = { item: item, handle: handle, list: item.parentNode, order: Array.from(item.parentNode.children), pointerId: event.pointerId };
            item.classList.add('dashboard-dragging');
            if (handle.setPointerCapture) { handle.setPointerCapture(event.pointerId); }
            event.preventDefault();
        });
        form.addEventListener('pointermove', function (event) {
            if (!drag || event.pointerId !== drag.pointerId) { return; }
            event.preventDefault();
            var target = document.elementFromPoint(event.clientX, event.clientY);
            target = target && target.closest('[data-dashboard-item]');
            if (!target || target === drag.item || target.parentNode !== drag.list) { return; }
            var rect = target.getBoundingClientRect();
            var before = event.clientY < rect.top + rect.height / 2;
            // Karten liegen auch nebeneinander: dann entscheidet die horizontale Hälfte.
            var own = drag.item.getBoundingClientRect();
            if (Math.abs(own.top - rect.top) < 10) { before = event.clientX < rect.left + rect.width / 2; }
            drag.list.insertBefore(drag.item, before ? target : target.nextSibling);
            var edge = 60;
            if (event.clientY < edge) { window.scrollBy(0, -16); }
            else if (event.clientY > window.innerHeight - edge) { window.scrollBy(0, 16); }
        });
        form.addEventListener('pointerup', function (event) { if (drag && event.pointerId === drag.pointerId) { finish(false); } });
        form.addEventListener('pointercancel', function (event) { if (drag && event.pointerId === drag.pointerId) { finish(true); } });
        form.addEventListener('lostpointercapture', function () { finish(true); });
        form.addEventListener('keydown', function (event) { if (event.key === 'Escape' && drag) { event.preventDefault(); finish(true); } });
        if (editable) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                save();
            });
        }
    });
})();
