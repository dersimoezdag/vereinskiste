// Finanz-Dashboard: zeichnet die Diagramme aus den vom Server gelieferten Zahlen (Chart.js, gebuendelt).
// Die Zahlen stehen als JSON im Block #dashboard-data; alle Werte gibt es zusaetzlich als Tabellen.
(function () {
    var root = document.querySelector('[data-dashboard]');
    var dataElement = document.getElementById('dashboard-data');
    if (!root || !dataElement || typeof Chart === 'undefined') {
        return;
    }
    // Die Canvas-Farben werden einmal gelesen; nach dem Themawechsel neu laden.
    var theme = document.documentElement.getAttribute('data-theme');
    new MutationObserver(function () {
        if (document.documentElement.getAttribute('data-theme') !== theme) { window.location.reload(); }
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    var data = JSON.parse(dataElement.textContent);
    var styles = getComputedStyle(root);

    function token(name, fallback) {
        var value = styles.getPropertyValue(name).trim();
        return value !== '' ? value : fallback;
    }

    var color = {
        income: token('--viz-income', '#2a78d6'),
        expense: token('--viz-expense', '#eb6834'),
        ink: token('--viz-ink', '#0b0b0b'),
        muted: token('--viz-muted', '#52514e'),
        grid: token('--viz-grid', '#e6e7e9'),
        surface: token('--viz-surface', '#ffffff')
    };
    var locale = root.dataset.locale || 'de-DE';
    var label = {
        income: root.dataset.labelIncome,
        expense: root.dataset.labelExpense,
        cumulative: root.dataset.labelCumulative,
        result: root.dataset.labelResult
    };
    var money = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR' });
    var moneyShort = new Intl.NumberFormat(locale, { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
    var monthFormat = new Intl.DateTimeFormat(locale, { month: 'short', year: '2-digit' });
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = color.muted;
    Chart.defaults.animation = reducedMotion ? false : { duration: 400 };
    Chart.defaults.plugins.tooltip.padding = 10;

    var months = data.months.map(function (month) {
        return monthFormat.format(new Date(month + '-01T00:00:00'));
    });

    function canvas(id) {
        var element = document.getElementById(id);
        return element ? element.getContext('2d') : null;
    }

    function truncate(text, max) {
        return text.length > max ? text.slice(0, max - 1) + '…' : text;
    }

    var valueAxis = {
        beginAtZero: true,
        border: { display: false },
        grid: { color: color.grid },
        ticks: { callback: function (value) { return moneyShort.format(value); } }
    };

    Chart.defaults.plugins.tooltip.backgroundColor = color.surface;
    Chart.defaults.plugins.tooltip.titleColor = color.ink;
    Chart.defaults.plugins.tooltip.bodyColor = color.ink;
    Chart.defaults.plugins.tooltip.footerColor = color.ink;
    Chart.defaults.plugins.tooltip.borderColor = color.grid;
    Chart.defaults.plugins.tooltip.borderWidth = 1;
    Chart.defaults.plugins.tooltip.usePointStyle = true;

    // Sichtbarer Monatsbereich, gesteuert ueber die beiden Regler.
    var view = { start: 0, end: months.length - 1, type: 'bar' };
    function slice(values) { return values.slice(view.start, view.end + 1); }
    function fade(hex) { return hex + '33'; }

    // Fuellt eine Linie mit einem senkrechten Verlauf, wie eine Flaechengrafik.
    function gradient(hex) {
        return function (context) {
            var area = context.chart.chartArea;
            if (!area) { return fade(hex); }
            var fill = context.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
            fill.addColorStop(0, hex + '66');
            fill.addColorStop(1, hex + '05');
            return fill;
        };
    }

    // Klick auf einen Monat oeffnet dessen Buchungen, auf eine Saeule nur deren Richtung.
    function openBookings(chart, event) {
        var row = chart.getElementsAtEventForMode(event, 'index', { intersect: false }, false)[0];
        if (!row) { return; }
        var month = data.months[view.start + row.index];
        var parts = month.split('-');
        var last = new Date(Number(parts[0]), Number(parts[1]), 0).getDate();
        var query = new URLSearchParams({ from: month + '-01', to: month + '-' + String(last).padStart(2, '0') });
        var hit = chart.getElementsAtEventForMode(event, 'nearest', { intersect: true }, false)[0];
        if (hit && hit.datasetIndex < 2) { query.set('direction', hit.datasetIndex === 0 ? 'in' : 'out'); }
        if (root.dataset.account) { query.set('account_id', root.dataset.account); }
        window.location.href = '/bookings?' + query.toString();
    }
    function pointerOnHit(event, elements) {
        event.native.target.style.cursor = elements.length ? 'pointer' : 'default';
    }

    // Einnahmen und Ausgaben je Monat: Einnahmen nach oben, Ausgaben nach unten (in jeder Ansicht,
    // als Saeulen in derselben Saeule); die Ergebnislinie zeigt den Saldo dazwischen.
    function monthlyDatasets() {
        var line = view.type !== 'bar';
        var area = view.type === 'area';
        var series = [
            { label: label.income, data: slice(data.income), hex: color.income },
            { label: label.expense, data: slice(data.expense).map(function (value) { return -Math.abs(value); }), hex: color.expense }
        ].map(function (item) {
            return line
                ? { label: item.label, data: item.data, type: 'line', borderColor: item.hex, backgroundColor: area ? gradient(item.hex) : item.hex,
                    fill: area ? 'origin' : false, tension: 0.35, borderWidth: 2, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: item.hex }
                : { label: item.label, data: item.data, type: 'bar', backgroundColor: item.hex, borderColor: color.surface, borderWidth: 2,
                    borderRadius: 4, borderSkipped: 'start', maxBarThickness: 28, order: 2 };
        });
        var income = slice(data.income);
        var expense = slice(data.expense);
        series.push({ label: label.result, type: 'line', data: income.map(function (value, index) { return Math.round((value - expense[index]) * 100) / 100; }),
            borderColor: color.ink, backgroundColor: color.ink, borderDash: [5, 4], borderWidth: 2, tension: 0.35, pointRadius: 2, pointHoverRadius: 5, fill: false, order: 1 });
        return series;
    }

    var monthly = canvas('chart-monthly');
    var monthlyChart = null;
    function drawMonthly() {
        if (!monthly) { return; }
        if (monthlyChart) { monthlyChart.destroy(); }
        monthlyChart = new Chart(monthly, {
            type: 'bar',
            data: { labels: slice(months), datasets: monthlyDatasets() },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animations: { colors: false },
                interaction: { mode: 'index', intersect: false },
                onClick: function (event) { openBookings(monthlyChart, event); },
                onHover: pointerOnHit,
                // Nur die Saeulen stapeln (Linien stapelt Chart.js getrennt nach Typ); Nulllinie betont.
                scales: {
                    x: { stacked: view.type === 'bar', grid: { display: false }, border: { color: color.grid } },
                    y: Object.assign({}, valueAxis, {
                        stacked: view.type === 'bar',
                        grid: { color: function (item) { return item.tick && item.tick.value === 0 ? color.muted : color.grid; } }
                    })
                },
                plugins: {
                    // Klick auf einen Legendeneintrag blendet die Reihe aus und wieder ein.
                    legend: { position: 'top', align: 'start', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8,
                        sort: function (a, b) { return a.datasetIndex - b.datasetIndex; } } },
                    // Ausgaben ohne Vorzeichen anzeigen; das Ergebnis behaelt sein Vorzeichen.
                    tooltip: { itemSort: function (a, b) { return a.datasetIndex - b.datasetIndex; },
                        callbacks: { label: function (item) {
                            return item.dataset.label + ': ' + money.format(item.datasetIndex === 1 ? Math.abs(item.parsed.y) : item.parsed.y);
                        } } }
                }
            }
        });
    }
    drawMonthly();

    document.querySelectorAll('[data-chart-type]').forEach(function (button) {
        button.addEventListener('click', function () {
            view.type = button.dataset.chartType;
            document.querySelectorAll('[data-chart-type]').forEach(function (other) {
                other.classList.toggle('active', other === button);
                other.setAttribute('aria-pressed', String(other === button));
            });
            drawMonthly();
        });
    });

    // Beschriftet jeden Balken am Ende mit seinem Betrag (direkte Beschriftung statt Zahlenwand).
    var valueLabels = {
        id: 'valueLabels',
        afterDatasetsDraw: function (chart) {
            var context = chart.ctx;
            var values = chart.data.datasets[0].data;
            context.save();
            context.fillStyle = color.ink;
            context.font = '12px ' + Chart.defaults.font.family;
            context.textBaseline = 'middle';
            chart.getDatasetMeta(0).data.forEach(function (bar, index) {
                context.fillText(money.format(values[index]), bar.x + 6, bar.y);
            });
            context.restore();
        }
    };

    function categoryChart(id, rows, barColor) {
        var context = canvas(id);
        if (!context || !rows.length) {
            return;
        }
        new Chart(context, {
            type: 'bar',
            data: {
                labels: rows.map(function (row) { return row.name; }),
                datasets: [{
                    data: rows.map(function (row) { return row.amount; }),
                    backgroundColor: barColor,
                    borderColor: color.surface,
                    borderWidth: 2,
                    borderRadius: { topRight: 4, bottomRight: 4 },
                    borderSkipped: 'start',
                    maxBarThickness: 22
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { beginAtZero: true, grace: '30%', border: { display: false }, grid: { color: color.grid },
                        ticks: { callback: function (value) { return moneyShort.format(value); } } },
                    y: { grid: { display: false }, border: { color: color.grid },
                        ticks: { callback: function (value) { return truncate(this.getLabelForValue(value), 26); } } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (item) { return money.format(item.parsed.x); } } }
                }
            },
            plugins: [valueLabels]
        });
    }

    categoryChart('chart-income-categories', data.incomeByCategory, color.income);
    categoryChart('chart-expense-categories', data.expenseByCategory, color.expense);

    // Kumuliertes Ergebnis: Flaeche mit Verlauf (keine Legende, die Ueberschrift benennt sie); Nulllinie betont.
    var cumulative = canvas('chart-cumulative');
    var cumulativeChart = null;
    if (cumulative) {
        cumulativeChart = new Chart(cumulative, {
            type: 'line',
            data: {
                labels: slice(months),
                datasets: [{
                    label: label.cumulative,
                    data: slice(data.cumulative),
                    borderColor: color.income,
                    backgroundColor: gradient(color.income),
                    fill: 'origin',
                    borderWidth: 2,
                    tension: 0.35,
                    pointRadius: months.length > 24 ? 0 : 3,
                    pointHoverRadius: 6,
                    pointBackgroundColor: color.income,
                    pointBorderColor: color.surface,
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animations: { colors: false },
                interaction: { mode: 'index', intersect: false },
                onClick: function (event) { openBookings(cumulativeChart, event); },
                scales: {
                    x: { grid: { display: false }, border: { color: color.grid } },
                    y: {
                        border: { display: false },
                        grid: { color: function (item) { return item.tick && item.tick.value === 0 ? color.muted : color.grid; } },
                        ticks: { callback: function (value) { return moneyShort.format(value); } }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (item) { return item.dataset.label + ': ' + money.format(item.parsed.y); } } }
                }
            }
        });
    }

    // Bereichsregler: grenzt beide Zeitverlaeufe ein, ohne die Seite neu zu laden.
    var brush = document.querySelector('[data-chart-brush]');
    if (brush && months.length > 2) {
        var startInput = brush.querySelector('[data-brush-start]');
        var endInput = brush.querySelector('[data-brush-end]');
        var output = brush.querySelector('[data-brush-output]');
        [startInput, endInput].forEach(function (input) { input.max = String(months.length - 1); });
        startInput.value = '0';
        endInput.value = String(months.length - 1);
        brush.hidden = false;
        var applyBrush = function (changed) {
            var start = Number(startInput.value);
            var end = Number(endInput.value);
            if (start > end) {
                if (changed === startInput) { end = start; endInput.value = String(end); } else { start = end; startInput.value = String(start); }
            }
            view.start = start;
            view.end = end;
            output.textContent = months[start] + ' – ' + months[end];
            drawMonthly();
            if (cumulativeChart) {
                cumulativeChart.data.labels = slice(months);
                cumulativeChart.data.datasets[0].data = slice(data.cumulative);
                cumulativeChart.update();
            }
        };
        output.textContent = months[0] + ' – ' + months[months.length - 1];
        startInput.addEventListener('input', function () { applyBrush(startInput); });
        endInput.addEventListener('input', function () { applyBrush(endInput); });
    }
})();
