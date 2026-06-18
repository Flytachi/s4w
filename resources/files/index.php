<div class="page-header">
    <div>
        <h1>Files</h1>
    </div>
    <div class="page-actions">
        <select id="file-instance-select" class="glass-input compact-select"></select>
    </div>
</div>

<section class="file-layout">
    <div id="file-instance-card" class="panel glass file-side"></div>

    <section class="panel glass file-main">
        <div class="panel-title">
            <h3>Files</h3>
            <div class="title-actions">
                <button class="btn btn-secondary" onclick="showCreateFolderModal()"><i class="fas fa-folder-plus"></i>New folder</button>
                <button class="btn btn-primary" onclick="showFileUploadModal()"><i class="fas fa-upload"></i>Upload</button>
            </div>
        </div>
        <div id="file-breadcrumbs" class="breadcrumbs"></div>
        <div id="file-list" class="file-list"></div>
    </section>
</section>
