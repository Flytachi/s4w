const chartRegistry = [];

function clearCharts() {
    while (chartRegistry.length) {
        const chart = chartRegistry.pop();
        chart.destroy();
    }
}

function makeChart(id, config) {
    const node = document.getElementById(id);
    if (!node || typeof Chart === 'undefined') return null;

    const chart = new Chart(node, config);
    chartRegistry.push(chart);
    return chart;
}

function chartOptions(extra = {}) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { labels: { color: '#9aa4b2', boxWidth: 12 } }
        },
        scales: {
            y: {
                grid: { color: 'rgba(148, 163, 184, 0.12)' },
                ticks: { color: '#9aa4b2' }
            },
            x: {
                grid: { display: false },
                ticks: { color: '#9aa4b2' }
            }
        },
        ...extra
    };
}

function initOverviewCharts(state) {
    clearCharts();

    // Реальное: занято против свободного по всем хранилищам (был фейковый «рост за 6 мес»).
    makeChart('usageChart', {
        type: 'doughnut',
        data: {
            labels: ['Занято, GB', 'Свободно, GB'],
            datasets: [{
                data: [state.overview.usedGb, state.overview.freeGb],
                backgroundColor: ['#38bdf8', 'rgba(148, 163, 184, 0.28)'],
                borderWidth: 0
            }]
        },
        options: chartOptions({ scales: {} })
    });

    // Реальное: хранилища по статусам (были выдуманные счётчики запросов GET/PUT/...).
    const sc = state.overview.statusCounts;
    makeChart('trafficChart', {
        type: 'bar',
        data: {
            labels: ['ACTIVE', 'PENDING', 'INACTIVE', 'CREATED'],
            datasets: [{
                label: 'Кол-во хранилищ',
                data: [sc.ACTIVE, sc.PENDING, sc.INACTIVE, sc.CREATED],
                backgroundColor: ['#22c55e', '#f59e0b', '#ef4444', '#38bdf8'],
                borderRadius: 8
            }]
        },
        options: chartOptions({ scales: { y: { beginAtZero: true, grid: { color: 'rgba(148, 163, 184, 0.12)' }, ticks: { color: '#9aa4b2', precision: 0 } } } })
    });
}

function initAnalyticsCharts(state) {
    clearCharts();

    makeChart('clientChart', {
        type: 'doughnut',
        data: {
            labels: state.clients.map(client => client.name),
            datasets: [{
                data: state.clients.map(client => client.usedGb),
                backgroundColor: ['#38bdf8', '#22c55e', '#f59e0b', '#ef4444', '#a78bfa'],
                borderWidth: 0
            }]
        },
        options: chartOptions({ scales: {} })
    });

    makeChart('storageChart', {
        type: 'bar',
        data: {
            labels: state.storages.map(storage => storage.name),
            datasets: [{
                label: 'Использовано, GB',
                data: state.storages.map(storage => storage.usedGb),
                backgroundColor: '#38bdf8',
                borderRadius: 8
            }, {
                label: 'Лимит, GB',
                data: state.storages.map(storage => storage.limitGb),
                backgroundColor: 'rgba(148, 163, 184, 0.28)',
                borderRadius: 8
            }]
        },
        options: chartOptions()
    });

    // formatChart строится отдельно (updateFormatChart) — зависит от выбранного instance.
}

let formatChartInstance = null;

// Реальное распределение форматов выбранного instance. Раньше сюда уходил {unknown:1}.
function updateFormatChart(formats) {
    const node = document.getElementById('formatChart');
    if (!node || typeof Chart === 'undefined') return;
    if (formatChartInstance) {
        formatChartInstance.destroy();
        formatChartInstance = null;
    }
    formatChartInstance = new Chart(node, {
        type: 'polarArea',
        data: {
            labels: Object.keys(formats),
            datasets: [{
                data: Object.values(formats),
                backgroundColor: ['#38bdf8', '#22c55e', '#f59e0b', '#ef4444', '#a78bfa', '#14b8a6', '#f472b6', '#34d399']
            }]
        },
        options: chartOptions({ scales: { r: { ticks: { display: false }, grid: { color: 'rgba(148, 163, 184, 0.16)' } } } })
    });
}
