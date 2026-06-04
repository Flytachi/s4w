document.addEventListener('DOMContentLoaded', () => {
    const state = {
        instances: [],
        selectedInstanceId: new URLSearchParams(window.location.search).get('storage') || '',
    };

    const tokenKey = 's4w_jwt';
    const searchInput = document.getElementById('global-search');
    const quickClient = document.getElementById('quick-client');
    const quickStorage = document.getElementById('quick-storage');
    const transitionLinks = document.querySelectorAll('[data-transition-link]');
    const logout = document.querySelector('[data-logout]');

    window.showClientModal = () => showInstanceModal('Создать клиента');
    window.showStorageModal = () => showInstanceModal('Создать хранилище');
    window.showFileUploadModal = showFileUploadModal;
    window.closeModal = closeModal;
    window.showAlert = showAlert;
    window.toggleFrozen = id => showTokenModal(id);
    window.downloadFile = id => window.open(apiPath(`/s4w/instances/${state.selectedInstanceId}/media/${id}`), '_blank');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const term = searchInput.value.trim().toLowerCase();
            document.querySelectorAll('[data-search]').forEach(item => {
                item.hidden = term !== '' && !item.dataset.search.includes(term);
            });
        });
    }

    if (quickClient) quickClient.addEventListener('click', () => showInstanceModal('Создать клиента'));
    if (quickStorage) quickStorage.addEventListener('click', () => showInstanceModal('Создать хранилище'));
    if (logout) {
        logout.addEventListener('click', event => {
            event.preventDefault();
            localStorage.removeItem(tokenKey);
            document.body.classList.add('page-leaving');
            window.setTimeout(() => {
                window.location.href = logout.href;
            }, 160);
        });
    }

    transitionLinks.forEach(link => {
        link.addEventListener('click', event => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            document.body.classList.add('page-leaving');
            window.setTimeout(() => {
                window.location.href = link.href;
            }, 220);
        });
    });

    initPage().catch(error => {
        if (error.message === 'unauthorized') return;
        showAlert('danger', 'Ошибка API', error.message || 'Не удалось загрузить данные');
        renderEmptyStates('Не удалось загрузить данные через API');
    });

    async function initPage() {
        if (!localStorage.getItem(tokenKey)) {
            window.location.href = '/web/auth';
            throw new Error('unauthorized');
        }

        if (hasAny(['overview-stats', 'client-grid', 'storage-rows', 'file-instance-select', 'analytics-stats'])) {
            state.instances = await loadInstances();
        }

        if (document.getElementById('overview-stats')) renderOverview();
        if (document.getElementById('client-grid')) renderClients();
        if (document.getElementById('storage-rows')) renderStorages();
        if (document.getElementById('file-instance-select')) await renderFilesPage();
        if (document.getElementById('analytics-stats')) renderAnalytics();
    }

    async function loadInstances() {
        const query = new URLSearchParams({ limit: '100', page: '1' });
        if (searchInput?.value.trim()) query.set('search', searchInput.value.trim());
        const data = await apiJson(`/s4w/instances?${query.toString()}`);
        return Array.isArray(data.list) ? data.list : [];
    }

    async function apiJson(path, options = {}) {
        const response = await fetch(apiPath(path), {
            ...options,
            headers: apiHeaders(options.headers, options.body instanceof FormData),
        });

        if (response.status === 401 || response.status === 403) {
            localStorage.removeItem(tokenKey);
            window.location.href = '/web/auth';
            throw new Error('unauthorized');
        }

        if (!response.ok) {
            let message = `HTTP ${response.status}`;
            try {
                const data = await response.json();
                message = data.message || data.error || message;
            } catch (_) {
                const text = await response.text();
                if (text) message = text;
            }
            throw new Error(message);
        }

        if (response.status === 204) return null;
        const text = await response.text();
        return text ? JSON.parse(text) : null;
    }

    function apiHeaders(headers = {}, isForm = false) {
        const result = {
            Accept: 'application/json',
            Authorization: `Bearer ${localStorage.getItem(tokenKey) || ''}`,
            ...headers,
        };
        if (!isForm) result['Content-Type'] = 'application/json';
        return result;
    }

    function apiPath(path) {
        return path;
    }

    function renderOverview() {
        const totalQuota = sum(state.instances, item => bytes(item).quota);
        const totalUsed = sum(state.instances, item => bytes(item).used);
        const activeCount = state.instances.filter(item => statusName(item) === 'ACTIVE').length;
        const pendingCount = state.instances.filter(item => statusName(item) === 'PENDING').length;

        document.getElementById('overview-stats').innerHTML = [
            statCard('success', 'fa-building', `${activeCount} active`, state.instances.length, 'Instance'),
            statCard('warning', 'fa-database', `${pendingCount} pending`, state.instances.length, 'Хранилища'),
            statCard('info', 'fa-hard-drive', `${percent(totalUsed, totalQuota)}% от лимита`, formatBytes(totalUsed), 'Использовано'),
            statCard('success', 'fa-cubes', 'из API', formatBytes(totalQuota), 'Общая квота'),
        ].join('');

        const rows = document.getElementById('overview-instance-rows');
        rows.innerHTML = state.instances.slice(0, 5).map(instanceRow).join('') || emptyRow(7, 'Instance не найдены');

        initOverviewCharts?.(chartState());
    }

    function renderClients() {
        const grid = document.getElementById('client-grid');
        grid.innerHTML = state.instances.map(instance => {
            const used = bytes(instance).used;
            const quota = bytes(instance).quota;
            const pct = percent(used, quota);
            const name = escapeHtml(instance.name);
            return `
                <article class="client-card glass" data-search="${escapeAttr(`${instance.name} ${instance.description}`)}">
                    <div class="client-top">
                        <div class="client-avatar">${escapeHtml(initials(instance.name))}</div>
                        <span class="status-badge ${statusClass(instance)}">${escapeHtml(statusName(instance))}</span>
                    </div>
                    <h3>${name}</h3>
                    <p>${escapeHtml(instance.description || '')}</p>
                    <div class="quota-line">
                        <span>${formatBytes(used)} / ${formatBytes(quota)}</span>
                        <b>${pct}%</b>
                    </div>
                    <div class="progress"><span style="width:${pct}%"></span></div>
                    <div class="client-meta">
                        <span><i class="fas fa-server"></i>${escapeHtml(instance.id)}</span>
                    </div>
                </article>
            `;
        }).join('') || emptyBlock('Instance не найдены');
    }

    function renderStorages() {
        const rows = document.getElementById('storage-rows');
        rows.innerHTML = state.instances.map(instanceRow).join('') || emptyRow(7, 'Instance не найдены');
    }

    function instanceRow(instance) {
        const used = bytes(instance).used;
        const quota = bytes(instance).quota;
        const pct = percent(used, quota);
        return `
            <tr data-search="${escapeAttr(`${instance.name} ${instance.description}`)}">
                <td><strong>${escapeHtml(instance.name)}</strong><small>${escapeHtml(instance.id)}</small></td>
                <td>${escapeHtml(instance.description || '')}</td>
                <td>
                    <div class="quota-cell">
                        <span>${formatBytes(used)} / ${formatBytes(quota)}</span>
                        <div class="progress"><span style="width:${pct}%"></span></div>
                    </div>
                </td>
                <td><span class="status-badge ${statusClass(instance)}">${escapeHtml(statusName(instance))}</span></td>
                <td>${formatDate(instance.createdAt)}</td>
                <td>${formatDate(instance.updatedAt)}</td>
                <td>
                    <div class="row-actions">
                        <a class="icon-btn-sm" title="Файлы" href="/web/files?storage=${encodeURIComponent(instance.id)}"><i class="fas fa-folder-open"></i></a>
                        <button class="icon-btn-sm" title="Токены" onclick="toggleFrozen('${escapeAttr(instance.id)}')"><i class="fas fa-key"></i></button>
                        <button class="icon-btn-sm" title="Удалить" onclick="deleteInstance('${escapeAttr(instance.id)}')"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
        `;
    }

    async function renderFilesPage() {
        const select = document.getElementById('file-instance-select');
        if (!state.instances.length) {
            select.innerHTML = '<option value="">Нет instance</option>';
            document.getElementById('file-instance-card').innerHTML = emptyBlock('Создайте instance для работы с файлами');
            document.getElementById('file-list').innerHTML = emptyBlock('Файлы не загружены');
            return;
        }

        if (!state.selectedInstanceId || !state.instances.some(item => item.id === state.selectedInstanceId)) {
            state.selectedInstanceId = state.instances[0].id;
        }

        select.innerHTML = state.instances.map(item => `
            <option value="${escapeAttr(item.id)}" ${item.id === state.selectedInstanceId ? 'selected' : ''}>${escapeHtml(item.name)}</option>
        `).join('');
        select.addEventListener('change', () => {
            window.location.href = `/web/files?storage=${encodeURIComponent(select.value)}`;
        });

        const instance = state.instances.find(item => item.id === state.selectedInstanceId);
        renderFileInstanceCard(instance);
        await renderFiles(instance);
    }

    function renderFileInstanceCard(instance) {
        const used = bytes(instance).used;
        const quota = bytes(instance).quota;
        const pct = percent(used, quota);
        document.getElementById('file-instance-card').innerHTML = `
            <h3>${escapeHtml(instance.name)}</h3>
            <p>${escapeHtml(instance.description || '')}</p>
            <div class="storage-state ${statusName(instance) !== 'ACTIVE' ? 'is-frozen' : ''}">
                <i class="fas fa-${statusName(instance) === 'ACTIVE' ? 'lock-open' : 'lock'}"></i>
                <span>${escapeHtml(statusName(instance))}</span>
            </div>
            <div class="quota-line">
                <span>${formatBytes(used)} / ${formatBytes(quota)}</span>
                <b>${pct}%</b>
            </div>
            <div class="progress"><span style="width:${pct}%"></span></div>
        `;
    }

    async function renderFiles(instance) {
        const list = document.getElementById('file-list');
        const crumbs = document.getElementById('file-breadcrumbs');
        crumbs.innerHTML = `<button type="button">root</button>`;
        list.innerHTML = emptyBlock('Загрузка файлов через API...');

        try {
            const sections = await safeApi(`/s4w/instances/${instance.id}/files/sections`);
            const files = await safeApi(`/s4w/instances/${instance.id}/files?limit=50&page=1`);
            const folders = Array.isArray(sections?.list) ? sections.list : Array.isArray(sections) ? sections : [];
            const items = Array.isArray(files?.list) ? files.list : Array.isArray(files) ? files : [];
            list.innerHTML = [
                ...folders.map(folderRow),
                ...items.map(fileRow),
            ].join('') || emptyBlock('Файлы не найдены');
        } catch (error) {
            list.innerHTML = emptyBlock('File API пока не отвечает для выбранного instance');
        }
    }

    async function safeApi(path) {
        try {
            return await apiJson(path);
        } catch (error) {
            if (error.message === 'unauthorized') throw error;
            return null;
        }
    }

    function folderRow(folder) {
        const name = typeof folder === 'string' ? folder : folder.name;
        return `
            <div class="file-row folder" data-search="${escapeAttr(name)}">
                <i class="fas fa-folder"></i>
                <span>${escapeHtml(name)}</span>
                <small>Секция</small>
                <span></span>
            </div>
        `;
    }

    function fileIconClass(name) {
        const dot = String(name || '').lastIndexOf('.');
        const ext = dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
        const map = {
            pdf: 'fa-file-pdf',
            doc: 'fa-file-word', docx: 'fa-file-word', rtf: 'fa-file-word', odt: 'fa-file-word',
            xls: 'fa-file-excel', xlsx: 'fa-file-excel', csv: 'fa-file-csv', ods: 'fa-file-excel',
            ppt: 'fa-file-powerpoint', pptx: 'fa-file-powerpoint', odp: 'fa-file-powerpoint',
            jpg: 'fa-file-image', jpeg: 'fa-file-image', png: 'fa-file-image', gif: 'fa-file-image',
            webp: 'fa-file-image', svg: 'fa-file-image', bmp: 'fa-file-image', ico: 'fa-file-image', avif: 'fa-file-image',
            mp3: 'fa-file-audio', wav: 'fa-file-audio', ogg: 'fa-file-audio', flac: 'fa-file-audio', m4a: 'fa-file-audio', aac: 'fa-file-audio',
            mp4: 'fa-file-video', mov: 'fa-file-video', avi: 'fa-file-video', mkv: 'fa-file-video', webm: 'fa-file-video', flv: 'fa-file-video',
            zip: 'fa-file-zipper', rar: 'fa-file-zipper', '7z': 'fa-file-zipper', tar: 'fa-file-zipper', gz: 'fa-file-zipper', bz2: 'fa-file-zipper', xz: 'fa-file-zipper',
            txt: 'fa-file-lines', md: 'fa-file-lines', log: 'fa-file-lines',
            js: 'fa-file-code', mjs: 'fa-file-code', ts: 'fa-file-code', tsx: 'fa-file-code', jsx: 'fa-file-code',
            html: 'fa-file-code', htm: 'fa-file-code', css: 'fa-file-code', scss: 'fa-file-code', sass: 'fa-file-code',
            json: 'fa-file-code', xml: 'fa-file-code', yaml: 'fa-file-code', yml: 'fa-file-code', toml: 'fa-file-code',
            php: 'fa-file-code', py: 'fa-file-code', rb: 'fa-file-code', go: 'fa-file-code', rs: 'fa-file-code',
            java: 'fa-file-code', kt: 'fa-file-code', swift: 'fa-file-code', c: 'fa-file-code', h: 'fa-file-code',
            cpp: 'fa-file-code', hpp: 'fa-file-code', cs: 'fa-file-code', sh: 'fa-file-code', bash: 'fa-file-code', sql: 'fa-file-code',
        };
        return map[ext] || 'fa-file';
    }

    function fileRow(file) {
        const id = file.id || file.name || '';
        const name = file.name || id;
        const size = file.sizeBytes || file.size_bytes || file.size || 0;
        return `
            <div class="file-row" data-search="${escapeAttr(name)}">
                <i class="fas ${fileIconClass(name)}"></i>
                <span>${escapeHtml(name)}</span>
                <small>${formatBytes(size)} · ${formatDate(file.updatedAt || file.updated_at || file.createdAt || file.created_at)}</small>
                <button class="btn btn-secondary download-btn" onclick="downloadFile('${escapeAttr(id)}')">
                    <i class="fas fa-download"></i>Скачать
                </button>
            </div>
        `;
    }

    function renderAnalytics() {
        const select = document.getElementById('analytics-instance-select');
        select.innerHTML = state.instances.map(item => `
            <option value="${escapeAttr(item.id)}">${escapeHtml(item.name)}</option>
        `).join('') || '<option value="">Нет instance</option>';

        const totalQuota = sum(state.instances, item => bytes(item).quota);
        const totalUsed = sum(state.instances, item => bytes(item).used);
        const activeCount = state.instances.filter(item => statusName(item) === 'ACTIVE').length;

        document.getElementById('analytics-stats').innerHTML = [
            statCard('info', 'fa-gauge-high', `${formatBytes(totalUsed)} занято`, `${percent(totalUsed, totalQuota)}%`, 'Общая квота'),
            statCard('warning', 'fa-server', `${activeCount} active`, state.instances.length, 'Instance'),
            statCard('success', 'fa-bolt', 'из API', formatBytes(totalQuota), 'Лимит'),
            statCard('info', 'fa-file-lines', 'files API', 'N/A', 'Средний объект'),
        ].join('');

        initAnalyticsCharts?.(chartState());
    }

    function chartState() {
        const storages = state.instances.map(item => ({
            name: item.name,
            usedGb: bytesToGb(bytes(item).used),
            limitGb: bytesToGb(bytes(item).quota),
        }));
        return {
            clients: storages,
            storages,
            analytics: {
                usedTb: bytesToGb(sum(state.instances, item => bytes(item).used)) / 1024,
                formats: { unknown: 1 },
            },
        };
    }

    function showInstanceModal(title) {
        openModal(`
            <h3>${escapeHtml(title)}</h3>
            <form id="instance-form" class="admin-form">
                <div class="form-group">
                    <label>Название</label>
                    <input name="name" class="glass-input" required maxlength="100">
                </div>
                <div class="form-group">
                    <label>Описание</label>
                    <input name="description" class="glass-input" required maxlength="200">
                </div>
                <div class="form-group">
                    <label>Квота, MB</label>
                    <input name="quotaMb" type="number" min="1" class="glass-input" required value="100">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Отмена</button>
                    <button class="btn btn-primary" type="submit">Создать</button>
                </div>
            </form>
        `);

        document.getElementById('instance-form').addEventListener('submit', async event => {
            event.preventDefault();
            const data = new FormData(event.currentTarget);
            await apiJson('/s4w/instances', {
                method: 'POST',
                body: JSON.stringify({
                    name: data.get('name'),
                    description: data.get('description'),
                    quotaBytes: Number(data.get('quotaMb')) * 1024 * 1024,
                }),
            });
            closeModal();
            showAlert('success', 'Создано', String(data.get('name')));
            state.instances = await loadInstances();
            refreshCurrentPage();
        });
    }

    async function showTokenModal(instanceId) {
        const instance = state.instances.find(item => item.id === instanceId);
        openModal(`
            <h3>Токены: ${escapeHtml(instance?.name || instanceId)}</h3>
            <div id="created-token-box"></div>
            <div id="token-list" class="token-list">${emptyBlock('Загрузка токенов...')}</div>
            <form id="token-form" class="admin-form">
                <div class="form-group">
                    <label>Название токена</label>
                    <input name="name" class="glass-input" required maxlength="70">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Закрыть</button>
                    <button class="btn btn-primary" type="submit">Создать токен</button>
                </div>
            </form>
        `);

        await renderTokens(instanceId);
        document.getElementById('token-form').addEventListener('submit', async event => {
            event.preventDefault();
            const data = new FormData(event.currentTarget);
            const created = await apiJson(`/s4w/instances/${instanceId}/tokens`, {
                method: 'POST',
                body: JSON.stringify({ name: data.get('name') }),
            });
            renderCreatedToken(created.token || '');
            event.currentTarget.reset();
            showAlert('success', 'Токен создан', 'Скопируйте его сейчас');
            await renderTokens(instanceId);
        });
    }

    function renderCreatedToken(token) {
        const box = document.getElementById('created-token-box');
        if (!box || !token) return;

        box.innerHTML = `
            <div class="created-token glass">
                <div>
                    <strong>Новый токен</strong>
                    <p>Он показан только один раз. Скопируйте его сейчас.</p>
                </div>
                <code>${escapeHtml(token)}</code>
                <button class="btn btn-secondary" id="copy-created-token" type="button">
                    <i class="fas fa-copy"></i>Скопировать
                </button>
            </div>
        `;

        document.getElementById('copy-created-token').addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(token);
                showAlert('success', 'Скопировано', 'Токен в буфере обмена');
            } catch (_) {
                showAlert('warning', 'Не удалось скопировать', 'Скопируйте токен вручную');
            }
        });
    }

    async function renderTokens(instanceId) {
        const target = document.getElementById('token-list');
        const data = await apiJson(`/s4w/instances/${instanceId}/tokens?limit=50&page=1`);
        const tokens = Array.isArray(data.list) ? data.list : [];
        target.innerHTML = tokens.map(token => `
            <div class="file-row" data-search="${escapeAttr(token.name)}">
                <i class="fas fa-key"></i>
                <span>${escapeHtml(token.name)}</span>
                <small>${escapeHtml(token.status?.name || '')} · ${formatDate(token.createdAt)}</small>
                <button class="btn btn-secondary download-btn" onclick="changeTokenStatus('${escapeAttr(instanceId)}', '${escapeAttr(token.id)}', ${token.status?.id === 1 ? 0 : 1})">
                    ${token.status?.id === 1 ? 'Выключить' : 'Включить'}
                </button>
            </div>
        `).join('') || emptyBlock('Токены не найдены');
    }

    window.changeTokenStatus = async (instanceId, tokenId, status) => {
        await apiJson(`/s4w/instances/${instanceId}/tokens/${tokenId}/${status}`, { method: 'PATCH', body: '{}' });
        await renderTokens(instanceId);
    };

    window.deleteInstance = id => {
        openModal(`
            <h3>Удалить хранилище?</h3>
            <p class="modal-text">Это действие нельзя будет отменить. Хранилище будет удалено.</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">Отмена</button>
                <button class="btn btn-danger" id="confirm-delete-btn">Удалить</button>
            </div>
        `);

        document.getElementById('confirm-delete-btn').addEventListener('click', async () => {
            try {
                await apiJson(`/s4w/instances/${id}`, { method: 'DELETE' });
                closeModal();
                showAlert('success', 'Хранилище удаляется', 'Страница будет обновлена');
                window.setTimeout(() => {
                    window.location.reload();
                }, 1400);
            } catch (error) {
                showAlert('danger', 'Ошибка удаления', error.message || 'Не удалось удалить хранилище');
            }
        });
    };

    function showFileUploadModal() {
        if (!state.selectedInstanceId) {
            showAlert('warning', 'Instance не выбран', 'Выберите instance для загрузки файла');
            return;
        }

        openModal(`
            <h3>Загрузить файл</h3>
            <form id="file-upload-form" class="admin-form">
                <div class="form-group">
                    <label>Имя</label>
                    <input name="name" class="glass-input" required>
                </div>
                <div class="form-group">
                    <label>Файл</label>
                    <input id="upload-file-input" name="file" type="file" class="file-native-input" required>
                    <button id="upload-file-trigger" class="file-picker-btn" type="button">
                        <i class="fas fa-folder-open"></i>
                        <span>Выбрать файл</span>
                    </button>
                    <div id="upload-file-name" class="file-picker-name">Файл не выбран</div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Отмена</button>
                    <button class="btn btn-primary" type="submit">Загрузить</button>
                </div>
            </form>
        `);

        const fileInput = document.getElementById('upload-file-input');
        const fileName = document.getElementById('upload-file-name');
        document.getElementById('upload-file-trigger').addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            fileName.textContent = fileInput.files[0]?.name || 'Файл не выбран';
        });

        document.getElementById('file-upload-form').addEventListener('submit', async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const data = new FormData(form);
            const file = data.get('file');
            const body = new FormData();
            body.set('name', data.get('name'));
            body.set('file', file);
            await apiJson(`/s4w/instances/${state.selectedInstanceId}/files`, { method: 'POST', body });
            closeModal();
            showAlert('success', 'Файл загружен', String(data.get('name')));
            await renderFiles(state.instances.find(item => item.id === state.selectedInstanceId));
        });
    }

    function refreshCurrentPage() {
        if (document.getElementById('overview-stats')) renderOverview();
        if (document.getElementById('client-grid')) renderClients();
        if (document.getElementById('storage-rows')) renderStorages();
        if (document.getElementById('analytics-stats')) renderAnalytics();
    }

    function openModal(content) {
        document.getElementById('modal-container').innerHTML = `
            <div class="modal-overlay active" onclick="if(event.target === this) closeModal()">
                <div class="modal-content glass">${content}</div>
            </div>
        `;
    }

    function closeModal() {
        document.querySelector('.modal-overlay')?.remove();
    }

    function showAlert(type, title, message) {
        const container = document.getElementById('alert-container');
        const alert = document.createElement('div');
        alert.className = `alert glass alert-${type}`;
        alert.innerHTML = `
            <div class="alert-icon"><i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'times-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i></div>
            <div class="alert-body"><strong>${escapeHtml(title)}</strong><p>${escapeHtml(message)}</p></div>
            <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        container.appendChild(alert);
        setTimeout(() => alert.remove(), 4200);
    }

    function renderEmptyStates(message) {
        document.querySelectorAll('[data-api-loading]').forEach(node => {
            node.innerHTML = emptyBlock(message);
        });
        ['overview-instance-rows', 'storage-rows'].forEach(id => {
            const node = document.getElementById(id);
            if (node) node.innerHTML = emptyRow(7, message);
        });
    }

    function statCard(type, icon, metric, value, label) {
        return `
            <div class="stat-card glass">
                <div class="header"><div class="icon ${type}"><i class="fas ${icon}"></i></div><span class="metric ${type}">${escapeHtml(String(metric))}</span></div>
                <div class="value">${escapeHtml(String(value))}</div>
                <div class="label">${escapeHtml(label)}</div>
            </div>
        `;
    }

    function emptyRow(cols, message) {
        return `<tr><td colspan="${cols}" class="empty-cell">${escapeHtml(message)}</td></tr>`;
    }

    function emptyBlock(message) {
        return `<div class="empty-state">${escapeHtml(message)}</div>`;
    }

    function hasAny(ids) {
        return ids.some(id => document.getElementById(id));
    }

    function bytes(instance) {
        return {
            quota: Number(instance?.bytes?.quota || instance?.quotaBytes || 0),
            used: Number(instance?.bytes?.used || instance?.usedBytes || 0),
        };
    }

    function statusName(instance) {
        return instance?.status?.name || 'UNKNOWN';
    }

    function statusClass(instance) {
        const status = statusName(instance);
        if (status === 'ACTIVE') return 'active';
        if (status === 'PENDING' || status === 'CREATED') return 'pending';
        return 'frozen';
    }

    function percent(value, total) {
        if (!total) return 0;
        return Math.min(100, Math.round((value / total) * 100));
    }

    function bytesToGb(value) {
        return Math.round((Number(value || 0) / 1024 / 1024 / 1024) * 10) / 10;
    }

    function formatBytes(value) {
        const bytes = Number(value || 0);
        if (bytes >= 1024 ** 4) return `${(bytes / 1024 ** 4).toFixed(2)} TB`;
        if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
        if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
        if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${bytes} B`;
    }

    function formatDate(value) {
        if (!value) return '';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function initials(name) {
        return String(name || '?').trim().slice(0, 2).toUpperCase();
    }

    function sum(items, cb) {
        return items.reduce((acc, item) => acc + Number(cb(item) || 0), 0);
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function escapeAttr(value) {
        return escapeHtml(String(value ?? '').toLowerCase());
    }
});
