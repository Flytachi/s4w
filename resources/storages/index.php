<div class="page-header">
    <div>
        <h1>Storages</h1>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" onclick="showStorageModal()"><i class="fas fa-plus"></i>New storage</button>
    </div>
</div>

<section class="panel glass">
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
            <tbody id="storage-rows">
            <tr><td colspan="7" class="empty-cell">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</section>
