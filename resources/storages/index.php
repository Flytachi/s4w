<div class="page-header">
    <div>
        <h1>Хранилища</h1>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" onclick="showStorageModal()"><i class="fas fa-plus"></i>Создать хранилище</button>
    </div>
</div>

<section class="panel glass">
    <div class="table-wrap">
        <table class="glass-table">
            <thead>
            <tr>
                <th>Название</th>
                <th>Описание</th>
                <th>Квота</th>
                <th class="col-center">Статус</th>
                <th>Дата создания</th>
                <th>Дата изменения</th>
                <th class="col-right">Действия</th>
            </tr>
            </thead>
            <tbody id="storage-rows">
            <tr><td colspan="7" class="empty-cell">Загрузка через API...</td></tr>
            </tbody>
        </table>
    </div>
</section>
