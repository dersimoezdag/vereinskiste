// Bedienelemente ohne Bibliothek: Dialoge (<dialog>), Aufklappmenues (Popover-API),
// mobile Seitenleiste und Kurzinfos an Hilfe-Knoepfen.
(function () {
    'use strict';

    // Dialoge: data-dialog-open="#id" oeffnet, data-dialog-close schliesst; Klick auf den Hintergrund schliesst.
    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        if (!target) { return; }
        var opener = target.closest('[data-dialog-open]');
        if (opener) {
            var dialog = document.querySelector(opener.dataset.dialogOpen);
            if (dialog && typeof dialog.showModal === 'function' && !dialog.open) { dialog.showModal(); }
            return;
        }
        var closer = target.closest('[data-dialog-close]');
        if (closer) {
            var open = closer.closest('dialog');
            if (open) { open.close(); }
            return;
        }
        if (target instanceof HTMLDialogElement && target.open) {
            var box = target.getBoundingClientRect();
            var inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;
            if (!inside) { target.close(); }
        }
    });

    // Mobile Seitenleiste ein- und ausklappen.
    document.querySelectorAll('[data-nav-toggle]').forEach(function (button) {
        var menu = document.getElementById(button.getAttribute('aria-controls'));
        if (!menu) { return; }
        button.addEventListener('click', function () {
            var open = menu.classList.toggle('is-open');
            button.setAttribute('aria-expanded', String(open));
        });
    });

    // Aufklappmenues: neben dem ausloesenden Knopf positionieren (oberhalb, wenn unten kein Platz ist).
    function place(popover, trigger) {
        var anchor = trigger.getBoundingClientRect();
        var box = popover.getBoundingClientRect();
        var gap = 8;
        var top = anchor.bottom + gap;
        if (popover.dataset.placement === 'top' || top + box.height > window.innerHeight - gap) {
            top = Math.max(gap, anchor.top - box.height - gap);
        }
        var left = popover.dataset.align === 'stretch' ? anchor.left : anchor.right - box.width;
        if (popover.dataset.align === 'stretch') { popover.style.width = anchor.width + 'px'; }
        left = Math.min(Math.max(gap, left), window.innerWidth - box.width - gap);
        popover.style.top = top + 'px';
        popover.style.left = left + 'px';
    }
    document.querySelectorAll('[popovertarget]').forEach(function (trigger) {
        var popover = document.getElementById(trigger.getAttribute('popovertarget'));
        if (!popover) { return; }
        popover.addEventListener('toggle', function (event) {
            var open = event.newState === 'open';
            trigger.setAttribute('aria-expanded', String(open));
            trigger.classList.toggle('show', open);
            if (open) { place(popover, trigger); }
        });
    });

    // Kurzinfos: ein gemeinsames Popover, gezeigt bei Hover, Fokus oder Antippen.
    var tip = null;
    var owner = null;
    function tooltip() {
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'tooltip';
            tip.id = 'vk-tooltip';
            tip.setAttribute('role', 'tooltip');
            tip.setAttribute('popover', 'manual');
            document.body.appendChild(tip);
        }
        return tip;
    }
    function show(element) {
        var text = element.dataset.tip || element.getAttribute('title') || '';
        if (!text || typeof HTMLElement.prototype.showPopover !== 'function') { return; }
        if (element.hasAttribute('title')) { element.dataset.tip = text; element.removeAttribute('title'); }
        var box = tooltip();
        box.textContent = text;
        if (!box.matches(':popover-open')) { box.showPopover(); }
        box.dataset.placement = 'top';
        place(box, element);
        var anchor = element.getBoundingClientRect();
        box.style.left = Math.min(Math.max(8, anchor.left + anchor.width / 2 - box.offsetWidth / 2), window.innerWidth - box.offsetWidth - 8) + 'px';
        element.setAttribute('aria-describedby', box.id);
        owner = element;
    }
    function hide() {
        if (tip && tip.matches(':popover-open')) { tip.hidePopover(); }
        if (owner) { owner.removeAttribute('aria-describedby'); owner = null; }
    }
    document.addEventListener('mouseover', function (event) {
        var element = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
        if (element && element !== owner) { show(element); }
    });
    document.addEventListener('mouseout', function (event) {
        var element = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
        if (element && element === owner && !element.contains(event.relatedTarget)) { hide(); }
    });
    document.addEventListener('focusin', function (event) {
        var element = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
        if (element) { show(element); }
    });
    document.addEventListener('focusout', function (event) {
        if (owner && event.target === owner) { hide(); }
    });
    // Hilfe-Knoepfe stehen oft in <label>: Klick schaltet die Info, ohne das Feld zu fokussieren.
    document.addEventListener('click', function (event) {
        var element = event.target instanceof Element ? event.target.closest('.help-tooltip-btn') : null;
        if (!element) { return; }
        event.preventDefault();
        if (owner === element) { hide(); } else { show(element); }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { hide(); }
    });
    window.addEventListener('scroll', hide, { passive: true });
})();
