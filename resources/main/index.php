<div class="page-header">
    <div>
        <h1>Обзор file store</h1>
        <p>Единый центр управления клиентами, bucket-хранилищами, лимитами и файлами.</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-secondary" onclick="showStorageModal()"><i class="fas fa-server"></i>Новое хранилище</button>
    </div>
</div>

<section id="overview-stats" class="grid stats-grid" data-api-loading></section>

<section class="dashboard-layout">
    <div class="panel glass">
        <div class="panel-title"><h3>Рост занятого объема</h3><span>последние 6 месяцев</span></div>
        <div class="chart-box"><canvas id="usageChart"></canvas></div>
    </div>
    <div class="panel glass">
        <div class="panel-title"><h3>Операции API</h3><span>GET / PUT / LIST / DELETE</span></div>
        <div class="chart-box"><canvas id="trafficChart"></canvas></div>
    </div>
</section>

<section class="panel glass">
    <div class="panel-title">
        <h3>Хранилища под наблюдением</h3>
        <a class="btn btn-secondary" href="/web/storages"><i class="fas fa-arrow-right"></i>Открыть список</a>
    </div>
    <div class="table-wrap">
        <table class="glass-table">
            <thead>
            <tr>
                <th>Название</th>
                <th>Описание</th>
                <th>Квота</th>
                <th>Статус</th>
                <th>Дата создания</th>
                <th>Дата изменения</th>
                <th>Действия</th>
            </tr>
            </thead>
            <tbody id="overview-instance-rows">
            <tr><td colspan="7" class="empty-cell">Загрузка через API...</td></tr>
            </tbody>
        </table>
    </div>
</section>
