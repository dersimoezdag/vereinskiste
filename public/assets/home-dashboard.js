// Pointer Events unterstützen Maus und Touch; die Pfeile bleiben per Tastatur bedienbar.
(function () {
    'use strict';
    document.querySelectorAll('[data-dashboard-form]').forEach(function (form) {
        form.classList.add('dashboard-ready');
        var status = form.querySelector('[data-dashboard-status]');
        var saving = false, dirty = false, blocked = false, drag = null;
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
            if (!form.hasAttribute('data-autosave') || blocked || saving || drag || !dirty) { return; }
            saving = true;
            dirty = false;
            message(window.vkText('js.reihenfolge_wird_gespeichert'));
            try {
                var response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } });
                var result = await response.json();
                if (!response.ok || !Number.isInteger(result.version)) { throw new Error(result.error || window.vkText('js.speichern_fehlgeschlagen_bitte_neu_laden_und_erneut_versuchen')); }
                form.querySelector('[name="version"]').value = result.version;
                message(dirty ? window.vkText('js.reihenfolge_wird_gespeichert') : window.vkText('js.reihenfolge_gespeichert'));
            } catch (error) {
                blocked = true;
                message(error.message || window.vkText('js.speichern_fehlgeschlagen_bitte_neu_laden'));
            } finally {
                saving = false;
                if (dirty && !blocked) { save(); }
            }
        }
        function changed(list) {
            positions(list);
            if (blocked) { return; }
            message(window.vkText('js.reihenfolge_geandert'));
            dirty = true;
            save();
        }
        form.querySelectorAll('[data-dashboard-list]').forEach(positions);
        form.addEventListener('click', function (event) {
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
            if (!handle || drag || event.button !== 0) { return; }
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
        if (form.hasAttribute('data-autosave')) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (blocked) { message(window.vkText('js.bitte_lade_die_seite_neu_bevor_du_erneut_speicherst')); return; }
                dirty = true;
                save();
            });
        }
    });
})();
