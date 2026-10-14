(function () {
    'use strict';
    var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    document.querySelectorAll('[data-admin-password]').forEach(function (group) {
        var input = group.querySelector('[data-password-input]');
        var generate = group.querySelector('[data-password-generate]');
        var show = group.querySelector('[data-password-show]');
        var status = group.querySelector('[data-password-status]');
        generate.hidden = false;
        generate.disabled = !window.crypto || typeof window.crypto.getRandomValues !== 'function';
        group.querySelector('[data-password-visibility]').hidden = false;
        show.addEventListener('change', function () { input.type = show.checked ? 'text' : 'password'; });
        generate.addEventListener('click', function () {
            var bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            input.value = Array.from(bytes, function (value) { return alphabet[value & 63]; }).join('');
            input.dispatchEvent(new Event('input', {bubbles: true}));
            input.dispatchEvent(new Event('change', {bubbles: true}));
            if (status) { status.textContent = window.vkText('js.password_generated'); }
            input.focus();
        });
    });
})();
