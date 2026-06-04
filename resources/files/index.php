<div class="page-header">
    <div>
        <h1>Файловый браузер</h1>
        <p>Выберите instance для просмотра файлов и секций.</p>
    </div>
    <div class="page-actions">
        <select id="file-instance-select" class="glass-input compact-select"></select>
    </div>
</div>

<section class="file-layout">
    <div id="file-instance-card" class="panel glass file-side"></div>

    <section class="panel glass file-main">
        <div class="panel-title">
            <h3>Файлы</h3>
            <button class="btn btn-primary" onclick="showFileUploadModal()"><i class="fas fa-upload"></i>Загрузить</button>
        </div>
        <div id="file-breadcrumbs" class="breadcrumbs"></div>
        <div id="file-list" class="file-list"></div>
    </section>
</section>
