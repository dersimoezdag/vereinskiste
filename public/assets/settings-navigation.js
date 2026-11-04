/* A small transition between application and settings, preserving normal page navigation. */
(function () {
    'use strict';
    var panel = document.querySelector('[data-navigation-panel]');
    if (!panel) { return; }
    var mode = panel.dataset.navigationMode;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var animations = [], leaving = false;
    function read(key) { try { return sessionStorage.getItem(key); } catch (error) { return null; } }
    function write(key, value) { try { sessionStorage.setItem(key, value); } catch (error) { /* Navigation also works without storage. */ } }
    function localPath(value) {
        if (!value || value[0] !== '/' || value.startsWith('//')) { return null; }
        try {
            var url = new URL(value, location.origin);
            return url.origin === location.origin ? url.pathname + url.search + url.hash : null;
        } catch (error) { return null; }
    }
    var back = panel.querySelector('[data-application-return]');
    if (back) { back.href = localPath(read('vk.application-return')) || '/'; }
    if (mode === 'application') { write('vk.application-return', location.pathname + location.search + location.hash); }
    function animate(element, frames, options) {
        if (!element || reduced || typeof element.animate !== 'function') { return null; }
        var animation = element.animate(frames, options); animations.push(animation); return animation;
    }
    var previous = read('vk.navigation-transition');
    if (previous) {
        write('vk.navigation-transition', '');
        try {
            var transition = JSON.parse(previous);
            if (transition.to === mode && Date.now() - transition.at < 10000) {
                var menu = document.getElementById('sidebar-menu');
                if (transition.menuOpen && menu) {
                    menu.classList.add('is-open');
                    var toggle = document.querySelector('[data-nav-toggle]');
                    if (toggle) { toggle.setAttribute('aria-expanded', 'true'); }
                }
                animate(panel, [{opacity: 0, transform: 'translateY(' + (mode === 'settings' ? '16px' : '-16px') + ')'}, {opacity: 1, transform: 'translateY(0)'}], {duration: 280, easing: 'cubic-bezier(.2,.8,.2,1)'});
            }
        } catch (error) { /* Ignore stale navigation state. */ }
    }
    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('[data-settings-entry], [data-application-return]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank') { return; }
        var destination = localPath(link.getAttribute('href'));
        if (!destination || leaving) { return; }
        var menu = document.getElementById('sidebar-menu');
        write('vk.navigation-transition', JSON.stringify({to: mode === 'settings' ? 'application' : 'settings', at: Date.now(), menuOpen: !!menu && menu.classList.contains('is-open')}));
        if (reduced || typeof panel.animate !== 'function') { return; }
        event.preventDefault(); leaving = true;
        var nav = panel.querySelector('nav');
        animate(nav, [{opacity: 1, transform: 'translateY(0)'}, {opacity: 0, transform: 'translateY(-12px)'}], {duration: 180, fill: 'forwards', easing: 'ease-in'});
        var offset = mode === 'application' ? panel.getBoundingClientRect().top - link.getBoundingClientRect().top : 12;
        var motion = animate(link, [{transform: 'translateY(0)', opacity: 1}, {transform: 'translateY(' + offset + 'px)', opacity: .35}], {duration: 220, fill: 'forwards', easing: 'cubic-bezier(.4,0,.2,1)'});
        var navigated = false;
        function navigate() { if (!navigated) { navigated = true; location.assign(destination); } }
        if (motion) { motion.finished.then(navigate, navigate); }
        window.setTimeout(navigate, 260);
    });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) { return; }
        animations.forEach(function (animation) { animation.cancel(); }); animations = []; leaving = false;
    });
})();
