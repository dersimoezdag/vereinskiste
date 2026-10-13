document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-invoice-suggestions]');
    var form = document.getElementById('invoice-form');
    if (!button || !form) { return; }
    var values = JSON.parse(button.dataset.invoiceSuggestions);
    Object.keys(values).forEach(function (name) {
        var field = form.querySelector('#invoice-' + name);
        if (!field || field.disabled || !['kind', 'supplier_name', 'number', 'invoice_date', 'due_date', 'currency', 'net', 'tax', 'gross'].includes(name)) { return; }
        var value = values[name];
        if (field.dataset.mask === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(value)) { value = value.slice(8) + '.' + value.slice(5, 7) + '.' + value.slice(0, 4); }
        field.value = value;
        field.dispatchEvent(new Event('input', {bubbles: true}));
        field.dispatchEvent(new Event('blur'));
    });
});
