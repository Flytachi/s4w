<div class="page-header">
    <div>
        <h1>File store overview</h1>
        <p>Unified control center for clients, bucket storages, limits and files.</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-secondary" onclick="showStorageModal()"><i class="fas fa-server"></i>New storage</button>
    </div>
</div>

<section id="overview-stats" class="grid stats-grid" data-api-loading></section>

<section class="dashboard-layout">
    <div class="panel glass">
        <div class="panel-title"><h3>Quota usage</h3><span>used / free, GB</span></div>
        <div class="chart-box"><canvas id="usageChart"></canvas></div>
    </div>
    <div class="panel glass">
        <div class="panel-title"><h3>Storages by status</h3><span>ACTIVE / PENDING / INACTIVE / CREATED</span></div>
        <div class="chart-box"><canvas id="trafficChart"></canvas></div>
    </div>
</section>

<section class="panel glass">
    <div class="panel-title">
        <h3>Monitored storages</h3>
        <a class="btn btn-secondary" href="/web/storages"><i class="fas fa-arrow-right"></i>Open list</a>
    </div>
    <div class="table-wrap">
        <table class="glass-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Quota</th>
                <th class="col-center">Status</th>
                <th>Created</th>
                <th>Updated</th>
                <th class="col-right">Actions</th>
            </tr>
            </thead>
            <tbody id="overview-instance-rows">
            <tr><td colspan="7" class="empty-cell">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</section>
