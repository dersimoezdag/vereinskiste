/* Progressive table controls. Only rows supplied by the server are searched. */
(function () {
    'use strict';
    var collator = new Intl.Collator('de', {numeric: true, sensitivity: 'base'});
    function enhance(grid) {
        if (grid.dataset.gridReady) { return; }
        grid.dataset.gridReady = '1';
        var rows = Array.from(grid.querySelectorAll('[data-grid-row]'));
        var body = grid.querySelector('[data-grid-body]');
        var search = grid.querySelector('[data-grid-search]');
        var size = grid.querySelector('[data-grid-page-size]');
        var previous = grid.querySelector('[data-grid-prev]');
        var next = grid.querySelector('[data-grid-next]');
        var buttons = Array.from(grid.querySelectorAll('[data-grid-sort]'));
        var mobileSort = grid.querySelector('[data-grid-mobile-sort]');
        var serverPaged = grid.hasAttribute('data-grid-server-paged');
        var page = 0, sortColumn = null, direction = 1;
        var indexed = rows.map(function (row, index) {
            return {node: row, index: index, text: normalize(row.textContent)};
        });
        function normalize(value) {
            return value.toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        function render() {
            if (mobileSort) { mobileSort.value = sortColumn === null ? '' : sortColumn + ':' + direction; }
            var terms = normalize(search.value).trim().split(/\s+/).filter(Boolean);
            var matches = indexed.filter(function (row) {
                return terms.every(function (term) { return row.text.includes(term); });
            });
            if (sortColumn !== null) {
                matches.sort(function (a, b) {
                    var av = a.node.cells[sortColumn].dataset.gridValue;
                    var bv = b.node.cells[sortColumn].dataset.gridValue;
                    var numeric = a.node.cells[sortColumn].dataset.gridType === 'number' && av !== '' && bv !== '';
                    var comparison = numeric ? Number(av) - Number(bv) : collator.compare(av, bv);
                    return direction * comparison || a.index - b.index;
                });
            }
            else { matches.sort(function (a, b) { return a.index - b.index; }); }
            var limit = serverPaged || size.value === 'all' ? Math.max(1, matches.length) : Number(size.value);
            var pages = Math.max(1, Math.ceil(matches.length / limit));
            page = Math.min(page, pages - 1);
            rows.forEach(function (row) { row.hidden = true; });
            matches.forEach(function (row, index) {
                row.node.hidden = index < page * limit || index >= (page + 1) * limit;
                body.appendChild(row.node);
            });
            grid.querySelector('[data-grid-empty]').hidden = rows.length !== 0;
            grid.querySelector('[data-grid-no-results]').hidden = rows.length === 0 || matches.length !== 0;
            var start = matches.length ? page * limit + 1 : 0;
            var end = Math.min((page + 1) * limit, matches.length);
            grid.querySelector('[data-grid-count]').textContent = window.vkText('js.grid_count')
                .replace('{start}', start).replace('{end}', end).replace('{filtered}', matches.length).replace('{total}', rows.length);
            grid.querySelector('[data-grid-page]').textContent = (page + 1) + ' / ' + pages;
            previous.disabled = page === 0;
            next.disabled = page + 1 >= pages;
            buttons.forEach(function (button) {
                var active = Number(button.dataset.gridSort) === sortColumn;
                if (active) { button.closest('th').setAttribute('aria-sort', direction === 1 ? 'ascending' : 'descending'); }
                else { button.closest('th').removeAttribute('aria-sort'); }
                button.querySelector('[data-grid-sort-icon]').textContent = active ? (direction === 1 ? '↑' : '↓') : '↕';
            });
        }
        if (mobileSort) {
            grid.querySelector('[data-grid-mobile-sort-control]').hidden = buttons.length === 0;
            mobileSort.addEventListener('change', function () {
                var selected = mobileSort.value.split(':');
                sortColumn = mobileSort.value === '' ? null : Number(selected[0]);
                direction = Number(selected[1]) || 1;
                page = 0; render();
            });
        }
        search.addEventListener('input', function () { page = 0; render(); });
        size.addEventListener('change', function () { page = 0; render(); });
        previous.addEventListener('click', function () { page--; render(); });
        next.addEventListener('click', function () { page++; render(); });
        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var column = Number(button.dataset.gridSort);
                direction = sortColumn === column ? -direction : 1;
                sortColumn = column; page = 0; render();
            });
        });
        grid.querySelector('[data-grid-controls]').hidden = serverPaged;
        var searchControl = grid.querySelector('[data-grid-search-control]');
        if (searchControl) { searchControl.hidden = serverPaged; }
        render();
    }
    function init() { document.querySelectorAll('[data-grid]').forEach(enhance); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
