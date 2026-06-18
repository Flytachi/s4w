<div class="page-header">
    <div>
        <h1>Analytics</h1>
        <p>Aggregates are built from current storage data.</p>
    </div>
    <div class="page-actions">
        <select id="analytics-instance-select" class="glass-input compact-select"></select>
    </div>
</div>

<section id="analytics-stats" class="grid stats-grid" data-api-loading></section>

<section class="analytics-grid">
    <div class="panel glass">
        <div class="panel-title"><h3>Usage by instance</h3><span>GB</span></div>
        <div class="chart-box"><canvas id="clientChart"></canvas></div>
    </div>
    <div class="panel glass">
        <div class="panel-title"><h3>Limit vs used</h3><span>by instance</span></div>
        <div class="chart-box"><canvas id="storageChart"></canvas></div>
    </div>
    <div class="panel glass">
        <div class="panel-title"><h3>Object formats</h3><span>if files API is available</span></div>
        <div class="chart-box"><canvas id="formatChart"></canvas></div>
    </div>
</section>
