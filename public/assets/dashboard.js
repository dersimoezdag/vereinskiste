// Finanz-Dashboard: zeichnet die Diagramme aus den vom Server gelieferten Zahlen (Chart.js, gebuendelt).
// Die Zahlen stehen als JSON im Block #dashboard-data; alle Werte gibt es zusaetzlich als Tabellen.
(function () {
    var root = document.querySelector('[data-dashboard]');
    var dataElement = document.getElementById('dashboard-data');
    if (!root || !dataElement || typeof Chart === 'undefined') {
        return;
    }
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
        cumulative: root.dataset.labelCumulative
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

    // Einnahmen und Ausgaben je Monat: gruppierte Saeulen, zwei Serien (Legende immer sichtbar).
    var monthly = canvas('chart-monthly');
    if (monthly) {
        new Chart(monthly, {
            type: 'bar',
            data: {
                labels: months,
                datasets: [
                    { label: label.income, data: data.income, backgroundColor: color.income },
                    { label: label.expense, data: data.expense, backgroundColor: color.expense }
                ].map(function (dataset) {
                    dataset.borderColor = color.surface;
                    dataset.borderWidth = 2;
                    dataset.borderRadius = { topLeft: 4, topRight: 4 };
                    dataset.borderSkipped = 'bottom';
                    dataset.maxBarThickness = 28;
                    return dataset;
                })
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: { x: { grid: { display: false }, border: { color: color.grid } }, y: valueAxis },
                plugins: {
                    legend: { position: 'top', align: 'start', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8 } },
                    tooltip: { callbacks: { label: function (item) { return item.dataset.label + ': ' + money.format(item.parsed.y); } } }
                }
            }
        });
    }

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

    // Kumuliertes Ergebnis: eine Linie (keine Legende, die Ueberschrift benennt sie); Nulllinie betont.
    var cumulative = canvas('chart-cumulative');
    if (cumulative) {
        new Chart(cumulative, {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: label.cumulative,
                    data: data.cumulative,
                    borderColor: color.ink,
                    borderWidth: 2,
                    tension: 0,
                    pointRadius: months.length > 24 ? 0 : 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: color.ink,
                    pointBorderColor: color.surface,
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
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
})();
