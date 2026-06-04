<div class="page-header">
    <div>
        <h1>Аналитика</h1>
        <p>Агрегаты строятся по текущим данным хранилищ.</p>
    </div>
    <div class="page-actions">
        <select id="analytics-instance-select" class="glass-input compact-select"></select>
    </div>
</div>

<section id="analytics-stats" class="grid stats-grid" data-api-loading></section>

<section class="analytics-grid">
    <div class="panel glass">
        <div class="panel-title"><h3>Использование по instance</h3><span>GB</span></div>
        <div class="chart-box"><canvas id="clientChart"></canvas></div>
    </div>
    <div class="panel glass">
        <div class="panel-title"><h3>Лимит и занятый объем</h3><span>по instance</span></div>
        <div class="chart-box"><canvas id="storageChart"></canvas></div>
    </div>
    <div class="panel glass">
        <div class="panel-title"><h3>Форматы объектов</h3><span>если доступен files API</span></div>
        <div class="chart-box"><canvas id="formatChart"></canvas></div>
    </div>
</section>
