/* Local email domain suggestions; typed addresses never leave the browser. */
(function () {
    'use strict';
    var domains = ['gmail.com', 'gmx.de', 'gmx.net', 'web.de', 'outlook.de', 'outlook.com',
        'hotmail.de', 'hotmail.com', 'icloud.com', 't-online.de', 'yahoo.de', 'yahoo.com',
        'aol.com', 'posteo.de', 'mailbox.org', 'proton.me', 'protonmail.com'];
    var sequence = 0;
    function enhance() {
        if (typeof document === 'undefined') { return; }
        document.querySelectorAll('input[type="email"], input[name="email"]').forEach(function (field) {
            if (field.dataset.emailSuggestionsReady || field.hasAttribute('list') || field.disabled || field.readOnly || field.multiple) { return; }
            field.dataset.emailSuggestionsReady = '1';
            var list = document.createElement('datalist');
            list.id = 'email-domains-' + (++sequence);
            field.setAttribute('list', list.id);
            field.insertAdjacentElement('afterend', list);
            function update() {
                list.replaceChildren();
                var match = /^([^\s@]+)@([^\s@]*)$/.exec(field.value);
                if (!match) { return; }
                var prefix = match[2].toLowerCase();
                domains.forEach(function (domain) {
                    var address = match[1] + '@' + domain;
                    if (!domain.startsWith(prefix) || domain === prefix || (field.maxLength >= 0 && address.length > field.maxLength)) { return; }
                    var option = document.createElement('option');
                    option.value = address;
                    list.append(option);
                });
            }
            field.addEventListener('input', update);
            field.addEventListener('focus', update);
        });
    }
    enhance();
    new MutationObserver(enhance).observe(document.body, {childList: true, subtree: true});
})();
