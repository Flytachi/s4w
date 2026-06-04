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

    makeChart('usageChart', {
        type: 'line',
        data: {
            labels: ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн'],
            datasets: [{
                label: 'Занято, TB',
                data: [4.8, 5.1, 5.9, 6.7, 7.4, state.analytics.usedTb],
                borderColor: '#38bdf8',
                backgroundColor: 'rgba(56, 189, 248, 0.14)',
                fill: true,
                tension: 0.35
            }]
        },
        options: chartOptions()
    });

    makeChart('trafficChart', {
        type: 'bar',
        data: {
            labels: ['GET', 'PUT', 'DELETE', 'LIST'],
            datasets: [{
                label: 'Запросы за 24ч',
                data: [18420, 5320, 640, 4110],
                backgroundColor: ['#22c55e', '#38bdf8', '#ef4444', '#f59e0b'],
                borderRadius: 8
            }]
        },
        options: chartOptions()
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

    makeChart('formatChart', {
        type: 'polarArea',
        data: {
            labels: Object.keys(state.analytics.formats),
            datasets: [{
                data: Object.values(state.analytics.formats),
                backgroundColor: ['#38bdf8', '#22c55e', '#f59e0b', '#ef4444', '#a78bfa', '#14b8a6']
            }]
        },
        options: chartOptions({ scales: { r: { ticks: { display: false }, grid: { color: 'rgba(148, 163, 184, 0.16)' } } } })
    });
}
