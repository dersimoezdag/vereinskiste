(function () {
    'use strict';
    var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    document.querySelectorAll('[data-admin-password]').forEach(function (group) {
        var input = group.querySelector('[data-password-input]');
        var generate = group.querySelector('[data-password-generate]');
        var show = group.querySelector('[data-password-show]');
        var status = group.querySelector('[data-password-status]');
        if (generate) {
            generate.hidden = false;
            generate.disabled = !window.crypto || typeof window.crypto.getRandomValues !== 'function';
            generate.addEventListener('click', function () {
                var bytes = new Uint8Array(24);
                window.crypto.getRandomValues(bytes);
                input.value = Array.from(bytes, function (value) { return alphabet[value & 63]; }).join('');
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.dispatchEvent(new Event('change', {bubbles: true}));
                if (status) { status.textContent = window.vkText('js.password_generated'); }
                input.focus();
            });
        }
        var visibility = group.querySelector('[data-password-visibility]');
        if (visibility) {
            visibility.hidden = false;
        }
        if (show && input) {
            show.addEventListener('change', function () { input.type = show.checked ? 'text' : 'password'; });
        }
    });

    var modeRadios = document.querySelectorAll('[data-password-mode]');
    var adminPasswordGroup = document.querySelector('[data-admin-password]');
    if (modeRadios.length > 0 && adminPasswordGroup) {
        var passwordInput = adminPasswordGroup.querySelector('[data-password-input]');
        var updatePasswordMode = function () {
            var selected = document.querySelector('[data-password-mode]:checked');
            var isManual = selected && selected.getAttribute('data-password-mode') === 'manual';
            adminPasswordGroup.hidden = !isManual;
            if (passwordInput) {
                passwordInput.required = isManual;
                if (!isManual) {
                    passwordInput.value = '';
                }
            }
        };
        modeRadios.forEach(function (radio) {
            radio.addEventListener('change', updatePasswordMode);
        });
        updatePasswordMode();
    }
})();
