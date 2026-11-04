// Bedienelemente ohne Bibliothek: Dialoge (<dialog>), Aufklappmenues (Popover-API mit Ersatz fuer
// aeltere Browser), mobile Seitenleiste und Kurzinfos an Hilfe-Knoepfen.
(function () {
    'use strict';

    var popoverApi = typeof HTMLElement.prototype.showPopover === 'function';
    if (!popoverApi) { document.documentElement.classList.add('no-popover'); }

    // Dialoge: data-dialog-open="#id" oeffnet, data-dialog-close schliesst; Klick auf den Hintergrund schliesst.
    // Geschlossen wird nur, wenn der Klick auch auf dem Hintergrund begann (sonst schliesst z. B. eine
    // Textauswahl, die ueber den Rand hinaus gezogen wird, den Dialog samt Eingaben).
    function outside(dialog, event) {
        var box = dialog.getBoundingClientRect();
        return event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom;
    }
    var pressedOnBackdrop = null;
    document.addEventListener('pointerdown', function (event) {
        var target = event.target;
        pressedOnBackdrop = target instanceof HTMLDialogElement && target.open && outside(target, event) ? target : null;
    });
    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var pressed = pressedOnBackdrop;
        pressedOnBackdrop = null;
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
        if (target instanceof HTMLDialogElement && target.open && pressed === target && outside(target, event)) {
            target.close();
        }
    });

    // Mobile Seitenleiste: oeffnet als Flaeche unter der App-Leiste und sperrt das Scrollen der Seite.
    // Schliesst per Knopf, Escape, Linkauswahl oder wenn die Ansicht auf Desktop-Breite wechselt.
    document.querySelectorAll('[data-nav-toggle]').forEach(function (button) {
        var menu = document.getElementById(button.getAttribute('aria-controls'));
        if (!menu) { return; }
        function setOpen(open) {
            menu.classList.toggle('is-open', open);
            document.documentElement.classList.toggle('nav-open', open);
            button.setAttribute('aria-expanded', String(open));
        }
        button.addEventListener('click', function () { setOpen(!menu.classList.contains('is-open')); });
        menu.addEventListener('click', function (event) {
            if (event.target instanceof Element && event.target.closest('a[href]')) { setOpen(false); }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && menu.classList.contains('is-open')) { setOpen(false); button.focus(); }
        });
        var desktop = window.matchMedia ? window.matchMedia('(min-width: 62rem)') : null;
        if (desktop && desktop.addEventListener) {
            desktop.addEventListener('change', function (event) { if (event.matches) { setOpen(false); } });
        }
    });

    // Aufklappmenues: neben dem ausloesenden Knopf positionieren (oberhalb, wenn unten kein Platz ist).
    function place(popover, trigger) {
        var anchor = trigger.getBoundingClientRect();
        var gap = 8;
        // Breite zuerst setzen, damit Hoehe und Begrenzung mit den endgueltigen Massen rechnen.
        if (popover.dataset.align === 'stretch') { popover.style.width = anchor.width + 'px'; }
        var box = popover.getBoundingClientRect();
        var top = anchor.bottom + gap;
        if (popover.dataset.placement === 'top' || top + box.height > window.innerHeight - gap) {
            top = Math.max(gap, anchor.top - box.height - gap);
        }
        var left = popover.dataset.align === 'stretch' ? anchor.left : anchor.right - box.width;
        left = Math.min(Math.max(gap, left), window.innerWidth - box.width - gap);
        popover.style.top = top + 'px';
        popover.style.left = left + 'px';
    }
    function opened(popover, trigger, open) {
        trigger.setAttribute('aria-expanded', String(open));
        trigger.classList.toggle('show', open);
        if (open) { place(popover, trigger); }
        popover.style.visibility = '';
    }
    var menus = [];
    document.querySelectorAll('[popovertarget]').forEach(function (trigger) {
        var popover = document.getElementById(trigger.getAttribute('popovertarget'));
        if (!popover) { return; }
        menus.push({ popover: popover, trigger: trigger });
        if (!popoverApi) { return; }
        // "toggle" kommt erst nach dem Anzeigen: bis zur Positionierung unsichtbar halten, damit nichts springt.
        popover.addEventListener('beforetoggle', function (event) {
            if (event.newState === 'open') { popover.style.visibility = 'hidden'; }
        });
        popover.addEventListener('toggle', function (event) { opened(popover, trigger, event.newState === 'open'); });
    });
    // Ersatz ohne Popover-API (z. B. Safari < 17): Klasse is-open schaltet das Menue.
    if (!popoverApi) {
        var closeMenus = function (except) {
            menus.forEach(function (menu) {
                if (menu.popover !== except && menu.popover.classList.contains('is-open')) {
                    menu.popover.classList.remove('is-open');
                    opened(menu.popover, menu.trigger, false);
                }
            });
        };
        document.addEventListener('click', function (event) {
            var target = event.target instanceof Element ? event.target : null;
            var trigger = target ? target.closest('[popovertarget]') : null;
            var menu = trigger ? menus.filter(function (m) { return m.trigger === trigger; })[0] : null;
            if (menu) {
                event.preventDefault();
                closeMenus(menu.popover);
                opened(menu.popover, trigger, menu.popover.classList.toggle('is-open'));
                return;
            }
            if (!target || !target.closest('.menu')) { closeMenus(null); }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeMenus(null); }
        });
    }

    // Kurzinfos: ein gemeinsames Popover, gezeigt bei Hover, Fokus oder Antippen.
    // Ein Klick auf einen Hilfe-Knopf "heftet" die Info an: Sie bleibt, bis erneut geklickt,
    // ausserhalb getippt, gescrollt oder Escape gedrueckt wird.
    var tip = null;
    var owner = null;
    var pinned = false;
    function tooltip() {
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'tooltip';
            tip.id = 'vk-tooltip';
            tip.setAttribute('role', 'tooltip');
            if (popoverApi) { tip.setAttribute('popover', 'manual'); }
            document.body.appendChild(tip);
        }
        return tip;
    }
    function isShown(box) {
        return popoverApi ? box.matches(':popover-open') : box.classList.contains('is-open');
    }
    function show(element) {
        var text = element.dataset.tip || element.getAttribute('title') || '';
        if (!text) { return; }
        if (element.hasAttribute('title')) { element.dataset.tip = text; element.removeAttribute('title'); }
        var box = tooltip();
        box.textContent = text;
        if (!isShown(box)) {
            if (popoverApi) { box.showPopover(); } else { box.classList.add('is-open'); }
        }
        box.dataset.placement = 'top';
        place(box, element);
        var anchor = element.getBoundingClientRect();
        box.style.left = Math.min(Math.max(8, anchor.left + anchor.width / 2 - box.offsetWidth / 2), window.innerWidth - box.offsetWidth - 8) + 'px';
        if (owner && owner !== element) { owner.removeAttribute('aria-describedby'); }
        element.setAttribute('aria-describedby', box.id);
        owner = element;
    }
    function hide() {
        if (tip && isShown(tip)) {
            if (popoverApi) { tip.hidePopover(); } else { tip.classList.remove('is-open'); }
        }
        if (owner) { owner.removeAttribute('aria-describedby'); owner = null; }
        pinned = false;
    }
    document.addEventListener('mouseover', function (event) {
        var element = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
        if (element && element !== owner && !pinned) { show(element); }
    });
    document.addEventListener('mouseout', function (event) {
        var element = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
        if (element && element === owner && !pinned && !element.contains(event.relatedTarget)) { hide(); }
    });
    document.addEventListener('focusin', function (event) {
        var element = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
        if (element && element !== owner) { pinned = false; show(element); }
    });
    document.addEventListener('focusout', function (event) {
        if (owner && event.target === owner && !pinned) { hide(); }
    });
    // Hilfe-Knoepfe stehen oft in <label>: Klick schaltet die Info, ohne das Feld zu fokussieren.
    // Hover und Fokus zeigen die Info schon vor dem Klick an; der Klick entscheidet daher ueber "angeheftet".
    document.addEventListener('click', function (event) {
        var target = event.target instanceof Element ? event.target : null;
        var element = target ? target.closest('.help-tooltip-btn') : null;
        if (!element) {
            if (pinned && !(target && tip && tip.contains(target))) { hide(); }
            return;
        }
        event.preventDefault();
        if (owner === element && pinned) { hide(); return; }
        show(element);
        pinned = true;
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { hide(); }
    });
    // Capture: auch Scrollen in Modals oder der Seitenleiste (blubbert nicht bis window) versteckt die Info.
    document.addEventListener('scroll', function () { if (owner) { hide(); } }, { capture: true, passive: true });
})();
