(function () {
    'use strict';
    document.querySelectorAll('[data-user-roles]').forEach(function (fieldset) {
        var board = fieldset.querySelector('[value="vorstand"]');
        var admin = fieldset.querySelector('[value="admin"]');
        if (!board || !admin) { return; }
        board.addEventListener('change', function () {
            if (board.checked) { admin.checked = true; }
            else if (admin.checked && window.confirm(window.vkText('js.roles_remove_admin'))) { admin.checked = false; }
        });
    });
})();
