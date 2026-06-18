document.addEventListener('DOMContentLoaded', () => {
    const state = {
        instances: [],
        selectedInstanceId: new URLSearchParams(window.location.search).get('storage') || '',
        selectedSection: null,
        fileSearch: '',
        fileSearchTimer: null,
    };

    const tokenKey = 's4w_jwt';
    const searchInput = document.getElementById('global-search');
    const quickClient = document.getElementById('quick-client');
    const quickStorage = document.getElementById('quick-storage');
    const transitionLinks = document.querySelectorAll('[data-transition-link]');
    const logout = document.querySelector('[data-logout]');

    window.showClientModal = () => showInstanceModal('New client');
    window.showStorageModal = () => showInstanceModal('New storage');
    window.showFileUploadModal = showFileUploadModal;
    window.showCreateFolderModal = showCreateFolderModal;
    window.closeModal = closeModal;
    window.showAlert = showAlert;
    window.toggleFrozen = id => showTokenModal(id);
    window.checkToken = id => showValidateTokenModal(id);
    window.copyInstanceId = id => copyText(id, 'Storage ID');
    window.editInstance = id => showInstanceEditModal(id);
    let openMenu = null;
    let openMenuHome = null; // {parent, nextSibling} — куда вернуть портал

    const closeAllDropdowns = () => {
        if (openMenu) {
            openMenu.classList.remove('open');
            openMenu.style.left = '';
            openMenu.style.top = '';
            if (openMenuHome?.parent) {
                openMenuHome.parent.insertBefore(openMenu, openMenuHome.nextSibling);
            }
            openMenu = null;
            openMenuHome = null;
        }
    };
    window.toggleDropdown = event => {
        event.stopPropagation();
        const btn = event.currentTarget;
        const menu = btn.nextElementSibling;
        const wasOpen = menu === openMenu;
        closeAllDropdowns();
        if (wasOpen) return;

        // Портал в body: у .glass-предков есть backdrop-filter, который делает их
        // containing block'ом для position:fixed — меню улетает. В body таких
        // предков нет, координаты считаются от вьюпорта корректно. Заодно это
        // решает обрезку overflow-контейнером таблицы (.table-wrap).
        openMenuHome = { parent: menu.parentNode, nextSibling: menu.nextSibling };
        document.body.appendChild(menu);
        openMenu = menu;
        menu.classList.add('open');

        const rect = btn.getBoundingClientRect();
        const width = menu.offsetWidth || 188;
        const height = menu.offsetHeight;
        const left = Math.max(8, rect.right - width);
        let top = rect.bottom + 6;
        if (top + height > window.innerHeight - 8) {
            top = Math.max(8, rect.top - height - 6);
        }
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
    };
    // Закрытие: клик вне, скролл (в т.ч. внутренних контейнеров — capture), ресайз.
    document.addEventListener('click', closeAllDropdowns);
    document.addEventListener('scroll', closeAllDropdowns, true);
    window.addEventListener('resize', closeAllDropdowns);
    window.downloadFile = (id, name) => downloadFileBlob(id, name);
    window.previewFile = (id, name, size) => showFilePreviewModal(id, name, size);
    window.deleteFile = (id, name) => showFileDeleteModal(id, name);
    window.renameFile = (id, name) => showRenameFileModal(id, name);
    window.moveFile = (id, name) => showMoveFileModal(id, name);
    window.renameSectionPrompt = name => showRenameSectionModal(name);
    window.deleteSectionPrompt = name => showDeleteSectionModal(name);
    window.toggleSectionVisibility = async (section, makePublic) => {
        try {
            await apiJson(`/s4w/instances/${state.selectedInstanceId}/files/sections/visibility`, {
                method: 'PATCH',
                body: JSON.stringify({ section, public: makePublic }),
            });
        } catch (error) {
            if (error.message !== 'unauthorized') {
                showAlert('danger', 'Error', error.message || 'Failed to change section visibility');
            }
            return;
        }
        showAlert('success', makePublic ? 'Section is public' : 'Section is private', section);
        await reloadFiles();
    };

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            if (document.getElementById('file-list')) {
                state.fileSearch = searchInput.value;
                if (state.fileSearchTimer) clearTimeout(state.fileSearchTimer);
                state.fileSearchTimer = setTimeout(() => {
                    const inst = state.instances.find(item => item.id === state.selectedInstanceId);
                    if (inst) renderFiles(inst);
                }, 300);
                return;
            }
            const term = searchInput.value.trim().toLowerCase();
            document.querySelectorAll('[data-search]').forEach(item => {
                item.hidden = term !== '' && !item.dataset.search.includes(term);
            });
        });
    }

    if (quickClient) quickClient.addEventListener('click', () => showInstanceModal('New client'));
    if (quickStorage) quickStorage.addEventListener('click', () => showInstanceModal('New storage'));
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
        showAlert('danger', 'API error', error.message || 'Failed to load data');
        renderEmptyStates('Failed to load data via API');
    });

    async function initPage() {
        if (!localStorage.getItem(tokenKey)) {
            window.location.href = '/web/auth';
            throw new Error('unauthorized');
        }

        if (hasAny(['overview-stats', 'client-grid', 'storage-rows', 'file-instance-select', 'analytics-stats'])) {
            showInitialLoaders();
            state.instances = await loadInstances();
        }

        if (document.getElementById('overview-stats')) renderOverview();
        if (document.getElementById('client-grid')) renderClients();
        if (document.getElementById('storage-rows')) renderStorages();
        if (document.getElementById('file-instance-select')) await renderFilesPage();
        if (document.getElementById('analytics-stats')) renderAnalytics();
    }

    function showInitialLoaders() {
        ['overview-stats', 'analytics-stats', 'client-grid'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.innerHTML = spinnerBlock('Loading data via API...');
        });
        ['overview-instance-rows', 'storage-rows'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.innerHTML = spinnerRow(7, 'Loading...');
        });
    }

    async function loadInstances() {
        const query = new URLSearchParams({ limit: '100', page: '1' });
        if (searchInput?.value.trim()) query.set('search', searchInput.value.trim());
        const data = await apiJson(`/s4w/instances?${query.toString()}`);
        return listOf(data);
    }

    function listOf(res) {
        if (!res) return [];
        if (Array.isArray(res)) return res;
        if (Array.isArray(res.data)) return res.data;
        if (Array.isArray(res.list)) return res.list;
        return [];
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

    // URL media-эндпоинта с учётом секции: для файла в корне /media/{id},
    // для файла в секции /media/{section}/{id} (root-форма отдаёт 404 на section-файле).
    // Отображаемые файлы всегда принадлежат текущей state.selectedSection.
    function mediaPath(id) {
        const base = `/s4w/instances/${state.selectedInstanceId}/media`;
        return state.selectedSection
            ? `${base}/${encodeURIComponent(state.selectedSection)}/${id}`
            : `${base}/${id}`;
    }

    function renderOverview() {
        const totalQuota = sum(state.instances, item => bytes(item).quota);
        const totalUsed = sum(state.instances, item => bytes(item).used);
        const activeCount = state.instances.filter(item => statusName(item) === 'ACTIVE').length;
        const pendingCount = state.instances.filter(item => statusName(item) === 'PENDING').length;

        document.getElementById('overview-stats').innerHTML = [
            statCard('success', 'fa-building', `${activeCount} active`, state.instances.length, 'Instance'),
            statCard('warning', 'fa-database', `${pendingCount} pending`, state.instances.length, 'Storages'),
            statCard('info', 'fa-hard-drive', `${percent(totalUsed, totalQuota)}% of limit`, formatBytes(totalUsed), 'Used'),
            statCard('success', 'fa-cubes', 'from API', formatBytes(totalQuota), 'Total quota'),
        ].join('');

        const rows = document.getElementById('overview-instance-rows');
        rows.innerHTML = state.instances.slice(0, 5).map(instanceRow).join('') || emptyRow(7, 'No instances found');

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
                <article class="client-card glass" data-search="${searchKey(`${instance.name} ${instance.description}`)}">
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
        }).join('') || emptyBlock('No instances found');
    }

    function renderStorages() {
        const rows = document.getElementById('storage-rows');
        rows.innerHTML = state.instances.map(instanceRow).join('') || emptyRow(7, 'No instances found');
    }

    function instanceRow(instance) {
        const used = bytes(instance).used;
        const quota = bytes(instance).quota;
        const pct = percent(used, quota);
        return `
            <tr data-search="${searchKey(`${instance.name} ${instance.description} ${instance.id}`)}">
                <td><strong>${escapeHtml(instance.name)}</strong></td>
                <td>${escapeHtml(instance.description || '')}</td>
                <td>
                    <div class="quota-cell">
                        <span>${formatBytes(used)} / ${formatBytes(quota)}</span>
                        <div class="progress"><span style="width:${pct}%"></span></div>
                    </div>
                </td>
                <td class="col-center"><span class="status-badge ${statusClass(instance)}">${escapeHtml(statusName(instance))}</span></td>
                <td>${formatDate(instance.createdAt)}</td>
                <td>${formatDate(instance.updatedAt)}</td>
                <td class="col-right">
                    <div class="row-actions">
                        <a class="icon-btn-sm" title="Files" href="/web/files?storage=${encodeURIComponent(instance.id)}"><i class="fas fa-folder-open"></i></a>
                        <div class="dropdown">
                            <button class="icon-btn-sm" title="Actions" onclick="toggleDropdown(event)"><i class="fas fa-ellipsis-vertical"></i></button>
                            <div class="dropdown-menu">
                                <button type="button" onclick="copyInstanceId('${escapeAttr(instance.id)}')"><i class="fas fa-copy"></i>Copy ID</button>
                                <button type="button" onclick="toggleFrozen('${escapeAttr(instance.id)}')"><i class="fas fa-key"></i>Tokens</button>
                                <button type="button" onclick="editInstance('${escapeAttr(instance.id)}')"><i class="fas fa-pen"></i>Edit</button>
                                <button type="button" class="danger" onclick="deleteInstance('${escapeAttr(instance.id)}')"><i class="fas fa-trash"></i>Delete</button>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    }

    async function renderFilesPage() {
        const select = document.getElementById('file-instance-select');
        if (!state.instances.length) {
            select.innerHTML = '<option value="">No instances</option>';
            document.getElementById('file-instance-card').innerHTML = emptyBlock('Create an instance to work with files');
            document.getElementById('file-list').innerHTML = emptyBlock('No files uploaded');
            return;
        }

        // selectedInstanceId приходит из ?storage= (переход по кнопке хранилища).
        // По переходу из сайдбара (/web/files без параметра) ничего не выбрано —
        // пользователь должен сам выбрать хранилище.
        const valid = state.selectedInstanceId
            && state.instances.some(item => item.id === state.selectedInstanceId);
        if (!valid) state.selectedInstanceId = '';

        const placeholder = `<option value="" ${state.selectedInstanceId ? '' : 'selected'}>— Select storage —</option>`;
        select.innerHTML = placeholder + state.instances.map(item => `
            <option value="${escapeAttr(item.id)}" ${item.id === state.selectedInstanceId ? 'selected' : ''}>${escapeHtml(item.name)}</option>
        `).join('');
        select.addEventListener('change', () => {
            window.location.href = select.value
                ? `/web/files?storage=${encodeURIComponent(select.value)}`
                : '/web/files';
        });

        if (searchInput) {
            searchInput.value = state.fileSearch;
            searchInput.placeholder = 'Search by file name...';
        }

        // Ничего не выбрано — приглашаем выбрать хранилище.
        if (!state.selectedInstanceId) {
            document.getElementById('file-instance-card').innerHTML = `
                <div class="file-empty-pick">
                    <i class="fas fa-hand-pointer"></i>
                    <p>Select a storage above to see its files and sections.</p>
                </div>
            `;
            document.getElementById('file-breadcrumbs').innerHTML = '';
            document.getElementById('file-list').innerHTML = emptyBlock('No storage selected');
            return;
        }

        const instance = state.instances.find(item => item.id === state.selectedInstanceId);
        renderFileInstanceCard(instance);
        await renderFiles(instance);
    }

    function renderFileInstanceCard(instance) {
        const used = bytes(instance).used;
        const quota = bytes(instance).quota;
        const pct = percent(used, quota);
        document.getElementById('file-instance-card').innerHTML = `
            <div class="fi-head">
                <div class="fi-avatar">${escapeHtml(initials(instance.name))}</div>
                <div class="fi-titles">
                    <h3>${escapeHtml(instance.name)}</h3>
                    <span class="status-badge ${statusClass(instance)}">${escapeHtml(statusName(instance))}</span>
                </div>
            </div>
            ${instance.description ? `<p class="fi-desc">${escapeHtml(instance.description)}</p>` : ''}
            <button type="button" class="fi-id" onclick="copyInstanceId('${escapeAttr(instance.id)}')" title="Copy storage ID">
                <span class="fi-id-label">ID</span>
                <code>${escapeHtml(instance.id)}</code>
                <i class="fas fa-copy"></i>
            </button>
            <div class="fi-quota">
                <div class="fi-quota-top">
                    <span>Used</span>
                    <b>${pct}%</b>
                </div>
                <div class="progress"><span style="width:${pct}%"></span></div>
                <div class="fi-quota-bottom">
                    <span>${formatBytes(used)}</span>
                    <span>of ${formatBytes(quota)}</span>
                </div>
            </div>
            <div class="fi-actions">
                <button class="btn btn-secondary" onclick="toggleFrozen('${escapeAttr(instance.id)}')">
                    <i class="fas fa-key"></i>Tokens
                </button>
                <button class="btn btn-secondary" onclick="checkToken('${escapeAttr(instance.id)}')">
                    <i class="fas fa-shield-halved"></i>Check token
                </button>
            </div>
        `;
    }

    // Перечитывает инстансы from API и обновляет карточку выбранного (квота/used).
    async function refreshInstanceInfo() {
        try {
            state.instances = await loadInstances();
        } catch (_) {
            return;
        }
        const instance = state.instances.find(item => item.id === state.selectedInstanceId);
        if (instance && document.getElementById('file-instance-card')) {
            renderFileInstanceCard(instance);
        }
    }

    async function renderFiles(instance) {
        const list = document.getElementById('file-list');
        const crumbs = document.getElementById('file-breadcrumbs');
        const section = state.selectedSection;
        const search = state.fileSearch.trim();
        crumbs.innerHTML = section
            ? `<button type="button" onclick="openSection(null)">root</button><span class="crumb-sep">/</span><button type="button" class="crumb-current">${escapeHtml(section)}</button>`
            : `<button type="button" class="crumb-current">root</button>`;
        list.innerHTML = emptyBlock('Loading files via API...');

        try {
            const params = new URLSearchParams({ limit: '50', page: '1' });
            if (section) params.set('section', section);
            if (search) params.set('search', search);
            const files = await safeApi(`/s4w/instances/${instance.id}/files?${params.toString()}`);
            const items = listOf(files);

            let folders = [];
            if (!section && !search) {
                const sections = await safeApi(`/s4w/instances/${instance.id}/files/sections`);
                folders = listOf(sections);
            }

            const emptyMsg = search
                ? `Nothing found for "${search}"`
                : (section ? 'Folder is empty' : 'No files found');

            const parts = [];
            if (folders.length) {
                parts.push(`<div class="section-label"><i class="fas fa-folder"></i> Sections</div>`);
                parts.push(`<div class="section-grid">${folders.map(folderChip).join('')}</div>`);
            }
            if (items.length) {
                if (folders.length) parts.push(`<div class="section-label"><i class="fas fa-file"></i> Files</div>`);
                parts.push(`<div class="file-rows">${items.map(fileRow).join('')}</div>`);
            } else if (!folders.length) {
                parts.push(emptyBlock(emptyMsg));
            } else {
                parts.push(emptyBlock(section ? 'Folder is empty' : 'No files in root'));
            }
            list.innerHTML = parts.join('');
        } catch (error) {
            list.innerHTML = emptyBlock('File API is not responding for the selected instance');
        }
    }

    window.openSection = name => {
        state.selectedSection = name || null;
        const instance = state.instances.find(item => item.id === state.selectedInstanceId);
        if (instance) renderFiles(instance);
    };

    async function safeApi(path) {
        try {
            return await apiJson(path);
        } catch (error) {
            if (error.message === 'unauthorized') throw error;
            return null;
        }
    }

    function folderChip(folder) {
        const name = typeof folder === 'string' ? folder : folder.name;
        const isPublic = typeof folder === 'object' && !!folder.public;
        return `
            <div class="section-chip" data-search="${searchKey(name)}">
                <button type="button" class="section-chip-open" onclick="openSection('${escapeAttr(name)}')">
                    <i class="fas fa-folder"></i>
                    <span class="section-chip-name">${escapeHtml(name)}</span>
                    <i class="fas ${isPublic ? 'fa-globe section-vis-pub' : 'fa-lock section-vis-priv'}" title="${isPublic ? 'Public section' : 'Private section'}"></i>
                </button>
                <div class="dropdown">
                    <button type="button" class="section-chip-edit" title="Actions" onclick="toggleDropdown(event)">
                        <i class="fas fa-ellipsis-vertical"></i>
                    </button>
                    <div class="dropdown-menu">
                        <button type="button" onclick="toggleSectionVisibility('${escapeAttr(name)}', ${isPublic ? 'false' : 'true'})"><i class="fas ${isPublic ? 'fa-lock' : 'fa-globe'}"></i>${isPublic ? 'Make private' : 'Make public'}</button>
                        <button type="button" onclick="renameSectionPrompt('${escapeAttr(name)}')"><i class="fas fa-pen"></i>Rename</button>
                        <button type="button" class="danger" onclick="deleteSectionPrompt('${escapeAttr(name)}')"><i class="fas fa-trash"></i>Delete</button>
                    </div>
                </div>
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
            mp4: 'fa-film', mov: 'fa-clapperboard', avi: 'fa-video', mkv: 'fa-photo-film', webm: 'fa-circle-play', flv: 'fa-video',
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
        const ext = String(file.extension || extOf(name) || '').toUpperCase();
        const date = formatDate(file.updatedAt || file.updated_at || file.createdAt || file.created_at);
        const meta = [ext, formatBytes(size), date].filter(Boolean).join(' · ');
        const dedup = file.deduplicated
            ? ' <span class="file-tag dedup" title="Deduplicated: content already existed, no extra space used">dedup</span>'
            : '';
        // Публичный файл → иконка-ссылка на /o URL (открывается в новой вкладке).
        const publicLink = file.publicUrl
            ? `<a class="icon-btn glass-btn file-public-link" href="${escapeAttr(file.publicUrl)}" target="_blank" rel="noopener" title="Public link"><i class="fas fa-arrow-up-right-from-square"></i></a>`
            : '';
        return `
            <div class="file-row" data-id="${escapeAttr(id)}" data-search="${searchKey(name)}">
                <i class="fas ${fileIconClass(name)}"></i>
                <span class="file-name" title="${escapeAttr(name)}">${escapeHtml(name)}</span>
                <small title="${escapeAttr(file.mime || '')}">${escapeHtml(meta)}${dedup}</small>
                <div class="row-actions">
                    ${publicLink}
                    <div class="dropdown">
                        <button class="icon-btn glass-btn" title="Actions" onclick="toggleDropdown(event)"><i class="fas fa-ellipsis-vertical"></i></button>
                        <div class="dropdown-menu">
                            <button type="button" onclick="previewFile('${escapeAttr(id)}', '${escapeAttr(name)}', ${Number(size) || 0})"><i class="fas fa-eye"></i>Preview</button>
                            <button type="button" onclick="downloadFile('${escapeAttr(id)}', '${escapeAttr(name)}')"><i class="fas fa-download"></i>Download</button>
                            <button type="button" onclick="renameFile('${escapeAttr(id)}', '${escapeAttr(name)}')"><i class="fas fa-pen"></i>Rename</button>
                            <button type="button" onclick="moveFile('${escapeAttr(id)}', '${escapeAttr(name)}')"><i class="fas fa-arrow-right-arrow-left"></i>Move</button>
                            <button type="button" class="danger" onclick="deleteFile('${escapeAttr(id)}', '${escapeAttr(name)}')"><i class="fas fa-trash"></i>Delete</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function renderAnalytics() {
        const select = document.getElementById('analytics-instance-select');
        select.innerHTML = state.instances.map(item => `
            <option value="${escapeAttr(item.id)}">${escapeHtml(item.name)}</option>
        `).join('') || '<option value="">No instances</option>';

        const totalQuota = sum(state.instances, item => bytes(item).quota);
        const totalUsed = sum(state.instances, item => bytes(item).used);
        const activeCount = state.instances.filter(item => statusName(item) === 'ACTIVE').length;

        const count = state.instances.length;
        const avgUsed = count ? totalUsed / count : 0;

        document.getElementById('analytics-stats').innerHTML = [
            statCard('info', 'fa-gauge-high', `${formatBytes(totalUsed)} used`, `${percent(totalUsed, totalQuota)}%`, 'Total quota'),
            statCard('warning', 'fa-server', `${activeCount} active`, count, 'Instance'),
            statCard('success', 'fa-bolt', 'total', formatBytes(totalQuota), 'Limit'),
            statCard('info', 'fa-scale-balanced', 'per instance', formatBytes(avgUsed), 'Average size'),
        ].join('');

        initAnalyticsCharts?.(chartState());

        // Реальное распределение форматов для выбранного instance (раньше селект был мёртвый).
        const refreshFormats = async () => {
            if (!select.value) {
                updateFormatChart?.({ 'no data': 1 });
                return;
            }
            const dist = await loadFormatDistribution(select.value);
            updateFormatChart?.(dist);
        };
        select.addEventListener('change', refreshFormats);
        refreshFormats();
    }

    async function loadFormatDistribution(instanceId) {
        const counts = {};
        try {
            const res = await safeApi(`/s4w/instances/${instanceId}/files?limit=200&page=1`);
            listOf(res).forEach(file => {
                const ext = String(file.extension || extOf(file.name) || 'other').toLowerCase();
                counts[ext] = (counts[ext] || 0) + 1;
            });
        } catch (_) { /* график останется с заглушкой */ }
        return Object.keys(counts).length ? counts : { 'no files': 1 };
    }

    function chartState() {
        const storages = state.instances.map(item => ({
            name: item.name,
            usedGb: bytesToGb(bytes(item).used),
            limitGb: bytesToGb(bytes(item).quota),
        }));
        const totalUsed = sum(state.instances, item => bytes(item).used);
        const totalQuota = sum(state.instances, item => bytes(item).quota);
        const statusCounts = { ACTIVE: 0, PENDING: 0, INACTIVE: 0, CREATED: 0 };
        state.instances.forEach(item => {
            const name = statusName(item);
            if (statusCounts[name] !== undefined) statusCounts[name] += 1;
        });
        return {
            clients: storages,
            storages,
            overview: {
                usedGb: bytesToGb(totalUsed),
                freeGb: Math.max(0, bytesToGb(totalQuota - totalUsed)),
                statusCounts,
            },
        };
    }

    function showInstanceModal(title) {
        openModal(`
            <h3>${escapeHtml(title)}</h3>
            <form id="instance-form" class="admin-form">
                <div class="form-group">
                    <label>Name</label>
                    <input name="name" class="glass-input" required maxlength="100">
                </div>
                <div class="form-group">
                    <label>Description <span class="optional-hint">— optional</span></label>
                    <input name="description" class="glass-input" maxlength="200" placeholder="Optional">
                </div>
                ${quotaField(500, 'MB')}
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" type="submit">Create</button>
                </div>
            </form>
        `);

        document.getElementById('instance-form').addEventListener('submit', async event => {
            event.preventDefault();
            const data = new FormData(event.currentTarget);
            const submitBtn = event.currentTarget.querySelector('button[type="submit"]');
            try {
                await withBtnLoading(submitBtn, () => apiJson('/s4w/instances', {
                    method: 'POST',
                    body: JSON.stringify({
                        name: data.get('name'),
                        description: data.get('description'),
                        quotaBytes: quotaToBytes(data.get('quota'), data.get('quotaUnit')),
                    }),
                }));
            } catch (error) {
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Creation error', error.message || 'Failed to create storage');
                }
                return;
            }
            closeModal();
            showAlert('success', 'Created', String(data.get('name')));
            state.instances = await loadInstances();
            refreshCurrentPage();
        });
    }

    function showInstanceEditModal(id) {
        const instance = state.instances.find(item => item.id === id);
        if (!instance) {
            showAlert('warning', 'Not found', 'Storage not found');
            return;
        }
        const quota = splitQuota(bytes(instance).quota);
        openModal(`
            <h3>Edit storage</h3>
            <form id="instance-edit-form" class="admin-form">
                <div class="form-group">
                    <label>Name</label>
                    <input name="name" class="glass-input" required maxlength="100" value="${escapeAttr(instance.name)}">
                </div>
                <div class="form-group">
                    <label>Description <span class="optional-hint">— optional</span></label>
                    <input name="description" class="glass-input" maxlength="200" placeholder="Optional" value="${escapeAttr(instance.description || '')}">
                </div>
                ${quotaField(quota.value, quota.unit)}
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
        `);

        document.getElementById('instance-edit-form').addEventListener('submit', async event => {
            event.preventDefault();
            const data = new FormData(event.currentTarget);
            const submitBtn = event.currentTarget.querySelector('button[type="submit"]');
            try {
                await withBtnLoading(submitBtn, () => apiJson(`/s4w/instances/${id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        name: data.get('name'),
                        description: data.get('description'),
                        quotaBytes: quotaToBytes(data.get('quota'), data.get('quotaUnit')),
                    }),
                }));
            } catch (error) {
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Save error', error.message || 'Failed to save storage');
                }
                return;
            }
            closeModal();
            showAlert('success', 'Saved', String(data.get('name')));
            state.instances = await loadInstances();
            refreshCurrentPage();
        });
    }

    function showValidateTokenModal(instanceId) {
        openModal(`
            <h3>Check token</h3>
            <form id="token-validate-form" class="admin-form">
                <div class="form-group">
                    <label>Token</label>
                    <input name="token" class="glass-input" required placeholder="s4w_..." autocomplete="off">
                </div>
                <div id="token-validate-result"></div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
                    <button class="btn btn-primary" type="submit">Check</button>
                </div>
            </form>
        `);
        document.getElementById('token-validate-form').addEventListener('submit', async event => {
            event.preventDefault();
            const token = String(new FormData(event.currentTarget).get('token') || '').trim();
            const submitBtn = event.currentTarget.querySelector('button[type="submit"]');
            const result = document.getElementById('token-validate-result');
            if (!token) return;
            let data;
            try {
                data = await withBtnLoading(submitBtn, () => apiJson(
                    `/s4w/instances/${instanceId}/tokens/validation`,
                    { method: 'POST', body: JSON.stringify({ token }) },
                ));
            } catch (error) {
                if (error.message === 'unauthorized') return;
                result.innerHTML = `<div class="validate-result invalid"><i class="fas fa-circle-xmark"></i> Token is invalid</div>`;
                return;
            }
            const active = data?.status?.id === 1;
            result.innerHTML = `
                <div class="validate-result valid">
                    <i class="fas fa-circle-check"></i>
                    <div>
                        <strong>Token is valid</strong>
                        <p>${escapeHtml(data?.name || '')} · <span class="token-status ${active ? 'on' : 'off'}">${escapeHtml(data?.status?.name || '')}</span></p>
                    </div>
                </div>
            `;
        });
    }

    async function showTokenModal(instanceId) {
        const instance = state.instances.find(item => item.id === instanceId);
        openModal(`
            <h3>Tokens: ${escapeHtml(instance?.name || instanceId)}</h3>
            <div id="created-token-box"></div>
            <div id="token-list" class="token-list">${emptyBlock('Loading tokens...')}</div>
            <form id="token-form" class="admin-form">
                <div class="form-group">
                    <label>New token</label>
                    <input name="name" class="glass-input" required maxlength="70" placeholder="Token name">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
                    <button class="btn btn-primary" type="submit">Create token</button>
                </div>
            </form>
        `);

        await renderTokens(instanceId);
        document.getElementById('token-form').addEventListener('submit', async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const data = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            let created;
            try {
                created = await withBtnLoading(submitBtn, () => apiJson(`/s4w/instances/${instanceId}/tokens`, {
                    method: 'POST',
                    body: JSON.stringify({ name: data.get('name') }),
                }));
            } catch (error) {
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Error', error.message || 'Failed to create token');
                }
                return;
            }
            renderCreatedToken(created?.token || '', data.get('name'));
            form.reset();
            showAlert('success', 'Token created', 'Copy it now');
            await renderTokens(instanceId);
        });
    }

    function renderCreatedToken(token, name) {
        const box = document.getElementById('created-token-box');
        if (!box || !token) return;

        box.innerHTML = `
            <div class="created-token glass">
                <div>
                    <strong>Token "${escapeHtml(name || '')}"</strong>
                    <p>Shown only once. Copy it now.</p>
                </div>
                <code>${escapeHtml(token)}</code>
                <button class="btn btn-secondary" id="copy-created-token" type="button">
                    <i class="fas fa-copy"></i>Copy
                </button>
            </div>
        `;

        document.getElementById('copy-created-token').addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(token);
                showAlert('success', 'Copied', 'Token copied to clipboard');
            } catch (_) {
                showAlert('warning', 'Failed to copy', 'Copy the token manually');
            }
        });
    }

    async function renderTokens(instanceId) {
        const target = document.getElementById('token-list');
        const data = await apiJson(`/s4w/instances/${instanceId}/tokens?limit=50&page=1`);
        const tokens = listOf(data);
        target.innerHTML = tokens.map(token => {
            const active = token.status?.id === 1;
            return `
            <div class="file-row" data-search="${searchKey(token.name)}">
                <i class="fas fa-key ${active ? 'token-on' : 'token-off'}"></i>
                <span>${escapeHtml(token.name)}</span>
                <small><span class="token-status ${active ? 'on' : 'off'}">${escapeHtml(token.status?.name || '')}</span> · ${formatDate(token.createdAt)}</small>
                <div class="row-actions">
                    <button class="icon-btn glass-btn token-toggle ${active ? 'is-active' : ''}" title="${active ? 'Disable token' : 'Enable token'}" onclick="changeTokenStatus('${escapeAttr(instanceId)}', '${escapeAttr(token.id)}', ${active ? 0 : 1})">
                        <i class="fas ${active ? 'fa-toggle-on' : 'fa-toggle-off'}"></i>
                    </button>
                    <button class="icon-btn glass-btn" title="Regenerate token" onclick="regenerateToken('${escapeAttr(instanceId)}', '${escapeAttr(token.id)}', '${escapeAttr(token.name)}')">
                        <i class="fas fa-rotate"></i>
                    </button>
                    <button class="icon-btn glass-btn token-delete" title="Delete token" onclick="deleteToken('${escapeAttr(instanceId)}', '${escapeAttr(token.id)}', '${escapeAttr(token.name)}')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
        }).join('') || emptyBlock('No tokens found');
    }

    window.changeTokenStatus = async (instanceId, tokenId, status) => {
        try {
            await apiJson(`/s4w/instances/${instanceId}/tokens/${tokenId}/${status}`, { method: 'PATCH', body: '{}' });
        } catch (error) {
            if (error.message !== 'unauthorized') {
                showAlert('danger', 'Error', error.message || 'Failed to change token status');
            }
            return;
        }
        await renderTokens(instanceId);
    };

    // Перевыпуск: старый токен сразу инвалидируется, новый показываем один раз.
    window.regenerateToken = async (instanceId, tokenId, name) => {
        if (!window.confirm('Regenerate the token? The old token will stop working immediately.')) return;
        let created;
        try {
            created = await apiJson(`/s4w/instances/${instanceId}/tokens/${tokenId}`, { method: 'PATCH', body: '{}' });
        } catch (error) {
            if (error.message !== 'unauthorized') {
                showAlert('danger', 'Error', error.message || 'Failed to regenerate token');
            }
            return;
        }
        renderCreatedToken(created?.token || '', name);
        showAlert('success', 'Token regenerated', 'Copy the new token now');
        await renderTokens(instanceId);
    };

    window.deleteToken = async (instanceId, tokenId, name) => {
        if (!window.confirm(`Delete token "${name}"? This cannot be undone.`)) return;
        try {
            await apiJson(`/s4w/instances/${instanceId}/tokens/${tokenId}`, { method: 'DELETE' });
        } catch (error) {
            if (error.message !== 'unauthorized') {
                showAlert('danger', 'Delete error', error.message || 'Failed to delete token');
            }
            return;
        }
        const box = document.getElementById('created-token-box');
        if (box) box.innerHTML = '';
        showAlert('success', 'Token deleted', name);
        await renderTokens(instanceId);
    };

    window.deleteInstance = id => {
        openModal(`
            <h3>Delete storage?</h3>
            <p class="modal-text">This action cannot be undone. The storage will be deleted.</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" id="confirm-delete-btn">Delete</button>
            </div>
        `);

        document.getElementById('confirm-delete-btn').addEventListener('click', async event => {
            try {
                await withBtnLoading(event.currentTarget, () => apiJson(`/s4w/instances/${id}`, { method: 'DELETE' }));
                closeModal();
                showAlert('success', 'Storage is being deleted', 'The page will refresh');
                window.setTimeout(() => {
                    window.location.reload();
                }, 1400);
            } catch (error) {
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Delete error', error.message || 'Failed to delete storage');
                }
            }
        });
    };

    function showCreateFolderModal() {
        if (!state.selectedInstanceId) {
            showAlert('warning', 'No storage selected', 'Select a storage first');
            return;
        }
        openModal(`
            <h3>New folder</h3>
            <form id="folder-form" class="admin-form">
                <div class="form-group">
                    <label>Section name</label>
                    <input name="name" class="glass-input" required autofocus
                        pattern="^[A-Za-z0-9][A-Za-z0-9_\\-]{0,99}$"
                        placeholder="Latin letters, digits, _ and -">
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input name="public" type="checkbox" value="1">
                        <span>Public (files accessible by direct link without a token)</span>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" type="submit">Create</button>
                </div>
            </form>
        `);
        document.getElementById('folder-form').addEventListener('submit', async event => {
            event.preventDefault();
            const data = new FormData(event.currentTarget);
            const name = String(data.get('name') || '').trim();
            const isPublic = data.get('public') === '1';
            const submitBtn = event.currentTarget.querySelector('button[type="submit"]');
            if (!name) return;
            try {
                await withBtnLoading(submitBtn, () => apiJson(`/s4w/instances/${state.selectedInstanceId}/files/sections`, {
                    method: 'POST',
                    body: JSON.stringify({ name, public: isPublic }),
                }));
            } catch (error) {
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Error', error.message || 'Failed to create folder');
                }
                return;
            }
            closeModal();
            showAlert('success', 'Folder created', name);
            await reloadFiles();
        });
    }

    function showFileUploadModal() {
        if (!state.selectedInstanceId) {
            showAlert('warning', 'No instance selected', 'Select an instance to upload a file');
            return;
        }

        openModal(`
            <h3>Upload file</h3>
            <form id="file-upload-form" class="admin-form">
                <div class="form-group">
                    <label>File <span class="required-mark" title="Required field">*</span></label>
                    <input id="upload-file-input" name="file" type="file" class="file-native-input" required>
                    <button id="upload-file-trigger" class="file-picker-btn" type="button">
                        <i class="fas fa-folder-open"></i>
                        <span id="upload-file-label">Choose file</span>
                    </button>
                </div>
                <div class="form-divider"><span>Optional parameters</span></div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Section (folder)</label>
                        <select name="section" id="upload-section-input" class="glass-input">
                            <option value="">— Root —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Name</label>
                        <input name="name" class="glass-input" placeholder="Same as file if empty">
                    </div>
                </div>
                <div id="upload-image-options" hidden>
                    <div class="form-group">
                        <label class="range-label">
                            <span>Image compression</span>
                            <span id="upload-compress-value" class="range-value">No compression</span>
                        </label>
                        <input id="upload-compress-input" name="compress" type="range" min="0" max="100" step="1" value="0" class="range-input">
                    </div>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input id="upload-webp-input" name="webp" type="checkbox" value="1">
                            <span>Convert to WebP</span>
                        </label>
                    </div>
                </div>
                <div id="upload-progress" class="upload-progress" hidden>
                    <div class="upload-progress-track"><span id="upload-progress-bar"></span></div>
                    <small id="upload-progress-text">0%</small>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" type="submit">Upload</button>
                </div>
            </form>
        `);

        const fileInput = document.getElementById('upload-file-input');
        const fileLabel = document.getElementById('upload-file-label');
        const compressInput = document.getElementById('upload-compress-input');
        const compressValue = document.getElementById('upload-compress-value');
        // Строгий select секций: только существующие (+ Корень), без автосоздания.
        const sectionSelect = document.getElementById('upload-section-input');
        safeApi(`/s4w/instances/${state.selectedInstanceId}/files/sections`).then(res => {
            listOf(res).forEach(s => {
                const name = typeof s === 'string' ? s : s.name;
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                if (name === state.selectedSection) opt.selected = true;
                sectionSelect.appendChild(opt);
            });
        }).catch(() => {});
        const imageOptions = document.getElementById('upload-image-options');
        const webpInput = document.getElementById('upload-webp-input');
        document.getElementById('upload-file-trigger').addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            const f = fileInput.files[0];
            fileLabel.textContent = f ? `${f.name} · ${formatBytes(f.size)}` : 'Choose file';
            // Сжатие/WebP поддерживаются только для изображений — показываем опции
            // лишь для них. Иначе скрываем и сбрасываем, чтобы не уходили в запрос.
            const isImage = !!f && (f.type.startsWith('image/') || isImageExt(extOf(f.name)));
            imageOptions.hidden = !isImage;
            if (!isImage) {
                compressInput.value = 0;
                webpInput.checked = false;
                renderCompress();
            }
        });
        const renderCompress = () => {
            const v = Number(compressInput.value);
            compressValue.textContent = v === 0 ? 'No compression' : `${v}%`;
        };
        compressInput.addEventListener('input', renderCompress);
        renderCompress();

        document.getElementById('file-upload-form').addEventListener('submit', async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const data = new FormData(form);
            const file = data.get('file');
            const name = String(data.get('name') || '').trim();
            const section = String(data.get('section') || '').trim();
            const compress = Number(data.get('compress') || 0);
            const webp = data.get('webp') === '1';
            const body = new FormData();
            if (name) body.set('name', name);
            if (section) body.set('section', section);
            body.set('file', file);
            if (compress > 0) body.set('compress', String(compress));
            if (webp) body.set('webp', '1');

            const submitBtn = form.querySelector('button[type="submit"]');
            const progressWrap = document.getElementById('upload-progress');
            const progressBar = document.getElementById('upload-progress-bar');
            const progressText = document.getElementById('upload-progress-text');
            submitBtn.disabled = true;
            submitBtn.classList.add('is-loading');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            if (progressWrap) progressWrap.hidden = false;

            try {
                await uploadWithProgress(`/s4w/instances/${state.selectedInstanceId}/files`, body, pct => {
                    if (progressBar) progressBar.style.width = `${pct}%`;
                    if (progressText) progressText.textContent = pct < 100 ? `${pct}%` : 'Processing on server...';
                });
            } catch (error) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('is-loading');
                submitBtn.innerHTML = 'Upload';
                if (progressWrap) progressWrap.hidden = true;
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Upload error', error.message || 'Failed to upload file');
                }
                return;
            }
            closeModal();
            showAlert('success', 'File uploaded', name || (file && file.name) || 'OK');
            // Обновляем инфо об instance (квота) и список файлов.
            await refreshInstanceInfo();
            const inst = state.instances.find(item => item.id === state.selectedInstanceId);
            if (inst) await renderFiles(inst);
        });
    }

    function isImageExt(ext) {
        return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'avif'].includes(ext);
    }

    function isPdfExt(ext) {
        return ext === 'pdf';
    }

    function isVideoExt(ext) {
        return ['mp4', 'mov', 'webm', 'mkv'].includes(ext);
    }

    function isAudioExt(ext) {
        return ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac'].includes(ext);
    }

    function isTextExt(ext) {
        return [
            'txt', 'md', 'log', 'csv', 'tsv',
            'json', 'xml', 'yaml', 'yml', 'toml', 'ini', 'conf', 'env',
            'js', 'mjs', 'ts', 'tsx', 'jsx', 'html', 'htm', 'css', 'scss', 'sass',
            'php', 'py', 'rb', 'go', 'rs', 'java', 'kt', 'swift', 'c', 'h', 'cpp', 'hpp',
            'cs', 'sh', 'bash', 'sql',
        ].includes(ext);
    }

    function extOf(name) {
        const dot = String(name || '').lastIndexOf('.');
        return dot >= 0 ? name.slice(dot + 1).toLowerCase() : '';
    }

    const PREVIEW_MAX_BYTES = 20 * 1024 * 1024; // превью только для файлов < 20 МБ

    async function showFilePreviewModal(id, name, size = 0) {
        if (!state.selectedInstanceId) {
            showAlert('warning', 'No instance selected', 'Select an instance to preview');
            return;
        }
        openModal(`
            <h3><i class="fas ${fileIconClass(name)}"></i> ${escapeHtml(name)}</h3>
            <div class="file-preview-body" id="file-preview-body">
                <div class="file-preview-fallback">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Loading...</p>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">Close</button>
                <button class="btn btn-primary" onclick="downloadFile('${escapeAttr(id)}', '${escapeAttr(name)}')">
                    <i class="fas fa-download"></i>Download
                </button>
            </div>
        `);

        const container = document.getElementById('file-preview-body');

        // Большие файлы не превьюим — грузить десятки МБ в браузер тяжело.
        if (Number(size) >= PREVIEW_MAX_BYTES) {
            container.innerHTML = `
                <div class="file-preview-fallback">
                    <i class="fas ${fileIconClass(name)} fa-3x"></i>
                    <p>File ${formatBytes(size)} — too large to preview (limit 20 MB).<br>Download it to open.</p>
                </div>
            `;
            return;
        }

        try {
            const response = await fetch(apiPath(mediaPath(id)), {
                headers: { Authorization: `Bearer ${localStorage.getItem(tokenKey) || ''}` },
            });
            if (response.status === 401 || response.status === 403) {
                localStorage.removeItem(tokenKey);
                window.location.href = '/web/auth';
                return;
            }
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const blob = await response.blob();
            const blobUrl = URL.createObjectURL(blob);
            state.previewBlobUrl = blobUrl;

            const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
            const ext = extOf(name);
            let inner;
            if (contentType.startsWith('image/') || isImageExt(ext)) {
                inner = `<img src="${escapeAttr(blobUrl)}" alt="${escapeAttr(name)}" class="file-preview-image">`;
            } else if (contentType === 'application/pdf' || isPdfExt(ext)) {
                inner = `<iframe src="${escapeAttr(blobUrl)}" class="file-preview-frame" title="${escapeAttr(name)}"></iframe>`;
            } else if (contentType.startsWith('video/') || isVideoExt(ext)) {
                inner = `<video src="${escapeAttr(blobUrl)}" controls class="file-preview-image"></video>`;
            } else if (contentType.startsWith('audio/') || isAudioExt(ext)) {
                inner = `<audio src="${escapeAttr(blobUrl)}" controls></audio>`;
            } else if (ext === 'md' || ext === 'markdown' || contentType.includes('markdown')) {
                const text = await blob.text();
                const html = (typeof marked !== 'undefined')
                    ? marked.parse(text, { gfm: true, breaks: true })
                    : escapeHtml(text);
                const safe = (typeof DOMPurify !== 'undefined') ? DOMPurify.sanitize(html) : html;
                inner = `<div class="file-preview-markdown markdown-body">${safe}</div>`;
            } else if (contentType.startsWith('text/') || contentType.includes('json') || contentType.includes('xml') || contentType.includes('yaml') || isTextExt(ext)) {
                const text = await blob.text();
                inner = `<pre class="file-preview-text">${escapeHtml(text)}</pre>`;
            } else {
                inner = `
                    <div class="file-preview-fallback">
                        <i class="fas ${fileIconClass(name)} fa-3x"></i>
                        <p>Preview is not available for this file type.</p>
                    </div>
                `;
            }
            if (container) container.innerHTML = inner;
        } catch (error) {
            if (container) {
                container.innerHTML = `
                    <div class="file-preview-fallback">
                        <i class="fas fa-triangle-exclamation fa-2x"></i>
                        <p>${escapeHtml(error.message || 'Failed to upload file')}</p>
                    </div>
                `;
            }
        }
    }

    function showFileDeleteModal(id, name) {
        if (!state.selectedInstanceId) {
            showAlert('warning', 'No instance selected', 'Select an instance to delete');
            return;
        }
        openModal(`
            <h3><i class="fas ${fileIconClass(name)}"></i> Delete file?</h3>
            <p class="modal-text">File <b>${escapeHtml(name)}</b> will be deleted. This cannot be undone.</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" id="confirm-file-delete-btn">Delete</button>
            </div>
        `);

        document.getElementById('confirm-file-delete-btn').addEventListener('click', async event => {
            try {
                await withBtnLoading(event.currentTarget, () => apiJson(`/s4w/instances/${state.selectedInstanceId}/files/${id}`, { method: 'DELETE' }));
                closeModal();
                showAlert('success', 'File deleted', name);
                // Просто убираем строку из списка (без полной перерисовки)...
                document.querySelector(`#file-list .file-row[data-id="${id}"]`)?.remove();
                // ...а инфо об instance (квоту) обновляем через 1 сек.
                setTimeout(refreshInstanceInfo, 1000);
            } catch (error) {
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Delete error', error.message || 'Failed to delete file');
                }
            }
        });
    }

    function reloadFiles() {
        const inst = state.instances.find(item => item.id === state.selectedInstanceId);
        return inst ? renderFiles(inst) : Promise.resolve();
    }

    function showRenameFileModal(id, currentName) {
        if (!state.selectedInstanceId) return;
        openModal(`
            <h3>Rename file</h3>
            <form id="file-rename-form" class="admin-form">
                <div class="form-group">
                    <label>Name</label>
                    <input name="name" class="glass-input" required maxlength="255" value="${escapeAttr(currentName)}">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
        `);
        document.getElementById('file-rename-form').addEventListener('submit', async event => {
            event.preventDefault();
            const newName = String(new FormData(event.currentTarget).get('name') || '').trim();
            const submitBtn = event.currentTarget.querySelector('button[type="submit"]');
            if (!newName) return;
            try {
                await withBtnLoading(submitBtn, () => apiJson(`/s4w/instances/${state.selectedInstanceId}/files/${id}/rename`, {
                    method: 'PATCH',
                    body: JSON.stringify({ name: newName }),
                }));
            } catch (error) {
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Error', error.message || 'Failed to rename');
                }
                return;
            }
            closeModal();
            showAlert('success', 'Renamed', newName);
            await reloadFiles();
        });
    }

    async function showMoveFileModal(id, name) {
        if (!state.selectedInstanceId) return;
        openModal(`
            <h3>Move file</h3>
            <p class="modal-text">"${escapeHtml(name)}" — choose destination:</p>
            <form id="file-move-form" class="admin-form">
                <div class="form-group">
                    <label>Section</label>
                    <select name="section" id="move-section-select" class="glass-input">
                        <option value="__root__">— Root —</option>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" type="submit">Move</button>
                </div>
            </form>
        `);
        const select = document.getElementById('move-section-select');
        const res = await safeApi(`/s4w/instances/${state.selectedInstanceId}/files/sections`);
        listOf(res).forEach(s => {
            const sec = typeof s === 'string' ? s : s.name;
            const opt = document.createElement('option');
            opt.value = sec;
            opt.textContent = sec;
            if (sec === state.selectedSection) opt.selected = true;
            select.appendChild(opt);
        });
        document.getElementById('file-move-form').addEventListener('submit', async event => {
            event.preventDefault();
            const section = select.value === '__root__' ? null : select.value;
            const submitBtn = event.currentTarget.querySelector('button[type="submit"]');
            try {
                await withBtnLoading(submitBtn, () => apiJson(`/s4w/instances/${state.selectedInstanceId}/files/${id}/move`, {
                    method: 'PATCH',
                    body: JSON.stringify({ section }),
                }));
            } catch (error) {
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Move error', error.message || 'Failed to move');
                }
                return;
            }
            closeModal();
            showAlert('success', 'Moved', name);
            await reloadFiles();
        });
    }

    function showRenameSectionModal(currentSection) {
        if (!state.selectedInstanceId) return;
        openModal(`
            <h3>Rename section</h3>
            <form id="section-rename-form" class="admin-form">
                <div class="form-group">
                    <label>Section name</label>
                    <input name="to" class="glass-input" required value="${escapeAttr(currentSection)}"
                        pattern="^[A-Za-z0-9][A-Za-z0-9_\\-]{0,99}$"
                        placeholder="Latin letters, digits, _ and -">
                    <small class="optional-hint">Renames the section for all files in it.</small>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
        `);
        document.getElementById('section-rename-form').addEventListener('submit', async event => {
            event.preventDefault();
            const to = String(new FormData(event.currentTarget).get('to') || '').trim();
            const submitBtn = event.currentTarget.querySelector('button[type="submit"]');
            if (!to || to === currentSection) {
                closeModal();
                return;
            }
            try {
                await withBtnLoading(submitBtn, () => apiJson(`/s4w/instances/${state.selectedInstanceId}/files/sections`, {
                    method: 'PATCH',
                    body: JSON.stringify({ from: currentSection, to }),
                }));
            } catch (error) {
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Error', error.message || 'Failed to rename section');
                }
                return;
            }
            closeModal();
            showAlert('success', 'Section renamed', `${currentSection} → ${to}`);
            if (state.selectedSection === currentSection) state.selectedSection = to;
            await reloadFiles();
        });
    }

    function showDeleteSectionModal(section) {
        if (!state.selectedInstanceId) return;
        openModal(`
            <h3><i class="fas fa-folder"></i> Delete section "${escapeHtml(section)}"?</h3>
            <p class="modal-text">The section and <b>all files inside it</b> will be permanently deleted. This cannot be undone.</p>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" id="confirm-section-delete-btn">Delete section</button>
            </div>
        `);
        document.getElementById('confirm-section-delete-btn').addEventListener('click', async event => {
            try {
                await withBtnLoading(event.currentTarget, () => apiJson(
                    `/s4w/instances/${state.selectedInstanceId}/files/sections/${encodeURIComponent(section)}`,
                    { method: 'DELETE' },
                ));
            } catch (error) {
                if (error.message !== 'unauthorized') {
                    showAlert('danger', 'Delete error', error.message || 'Failed to delete section');
                }
                return;
            }
            closeModal();
            showAlert('success', 'Section deleted', section);
            if (state.selectedSection === section) state.selectedSection = null;
            await refreshInstanceInfo();
            await reloadFiles();
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
        if (state.previewBlobUrl) {
            URL.revokeObjectURL(state.previewBlobUrl);
            state.previewBlobUrl = null;
        }
    }

    // Копирование в буфер с fallback для не-secure контекста (http по IP).
    async function copyText(text, label) {
        let ok = false;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                ok = true;
            }
        } catch (_) { ok = false; }
        if (!ok) {
            try {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                ok = document.execCommand('copy');
                ta.remove();
            } catch (_) { ok = false; }
        }
        if (ok) {
            showAlert('success', 'Copied', label || text);
        } else {
            showAlert('warning', 'Failed to copy', text);
        }
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

    // Поле квоты: число + единица [MB, GB]. Общая разметка для create/edit.
    function quotaField(value, unit) {
        return `
            <div class="form-group">
                <label>Quota</label>
                <div class="input-with-unit">
                    <input name="quota" type="number" min="1" step="1" class="glass-input" required value="${escapeAttr(String(value))}">
                    <select name="quotaUnit" class="glass-input unit-select">
                        <option value="MB" ${unit === 'MB' ? 'selected' : ''}>MB</option>
                        <option value="GB" ${unit === 'GB' ? 'selected' : ''}>GB</option>
                    </select>
                </div>
            </div>
        `;
    }

    function quotaToBytes(value, unit) {
        const mult = unit === 'GB' ? 1024 ** 3 : 1024 ** 2;
        return Math.round(Number(value || 0) * mult);
    }

    // Разбивает байты на {value, unit} для префилла: GB, если кратно гигабайту, иначе MB.
    function splitQuota(bytesValue) {
        const total = Number(bytesValue || 0);
        const gb = 1024 ** 3;
        if (total >= gb && total % gb === 0) {
            return { value: total / gb, unit: 'GB' };
        }
        return { value: Math.max(1, Math.round(total / (1024 ** 2))), unit: 'MB' };
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

    // Экранирование для значения в атрибуте/inline-onclick. Регистр СОХРАНЯЕМ —
    // иначе ломаются секции с заглавными (openSection) и искажаются имена.
    function escapeAttr(value) {
        return escapeHtml(String(value ?? ''));
    }

    // Ключ для data-search: регистронезависимый поиск сверяется с lowercase.
    function searchKey(value) {
        return escapeHtml(String(value ?? '').toLowerCase());
    }

    function spinnerBlock(message = 'Loading...') {
        return `<div class="loading-state"><i class="fas fa-spinner fa-spin"></i><span>${escapeHtml(message)}</span></div>`;
    }

    function spinnerRow(cols, message = 'Loading...') {
        return `<tr><td colspan="${cols}" class="empty-cell"><span class="loading-inline"><i class="fas fa-spinner fa-spin"></i> ${escapeHtml(message)}</span></td></tr>`;
    }

    // Блокирует кнопку и показывает спиннер на время async-операции (анти-двойной-клик + фидбек).
    async function withBtnLoading(button, fn) {
        if (!button) return fn();
        const original = button.innerHTML;
        button.disabled = true;
        button.classList.add('is-loading');
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        try {
            return await fn();
        } finally {
            button.disabled = false;
            button.classList.remove('is-loading');
            button.innerHTML = original;
        }
    }

    // Скачивание через fetch с Bearer-токеном (media-эндпоинт требует авторизацию,
    // поэтому window.open сюда не годится — он не шлёт заголовок).
    async function downloadFileBlob(id, name) {
        if (!state.selectedInstanceId) {
            showAlert('warning', 'No instance selected', 'Select an instance to download');
            return;
        }
        try {
            const response = await fetch(apiPath(mediaPath(id)), {
                headers: { Authorization: `Bearer ${localStorage.getItem(tokenKey) || ''}` },
            });
            if (response.status === 401 || response.status === 403) {
                localStorage.removeItem(tokenKey);
                window.location.href = '/web/auth';
                return;
            }
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = name || id;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (error) {
            showAlert('danger', 'Download error', error.message || 'Failed to download file');
        }
    }

    // Загрузка файла через XHR — даёт реальный прогресс (fetch его не отдаёт).
    function uploadWithProgress(path, formData, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', apiPath(path));
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('Authorization', `Bearer ${localStorage.getItem(tokenKey) || ''}`);
            xhr.upload.addEventListener('progress', event => {
                if (event.lengthComputable && onProgress) {
                    onProgress(Math.round((event.loaded / event.total) * 100));
                }
            });
            xhr.addEventListener('load', () => {
                if (xhr.status === 401 || xhr.status === 403) {
                    localStorage.removeItem(tokenKey);
                    window.location.href = '/web/auth';
                    reject(new Error('unauthorized'));
                    return;
                }
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(xhr.responseText ? JSON.parse(xhr.responseText) : null);
                    return;
                }
                let message = `HTTP ${xhr.status}`;
                try {
                    const data = JSON.parse(xhr.responseText);
                    message = data.message || data.error || message;
                } catch (_) { /* keep default */ }
                reject(new Error(message));
            });
            xhr.addEventListener('error', () => reject(new Error('Network error during upload')));
            xhr.send(formData);
        });
    }
});
