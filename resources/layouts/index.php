<?php
$resourceRoot = dirname(__DIR__);
require_once $resourceRoot . '/helpers.php';

$contentView = $contentView ?? 'main/index';
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/web/main', PHP_URL_PATH) ?: '/web/main';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Store Management Panel</title>
    <link rel="stylesheet" href="/static/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js"></script>
</head>
<body>
<div class="background-blobs">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
</div>

<div class="app-container">
    <aside class="sidebar glass">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-database"></i>
                <span>FileStore</span>
            </div>
            <p>Management-Panel</p>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li class="<?= str_starts_with($currentPath, '/web/main') ? 'active' : '' ?>" data-page="overview"><a href="/web/main"><i class="fas fa-chart-pie"></i><span>Обзор</span></a></li>
<!--                <li class="--><?php //= str_starts_with($currentPath, '/web/clients') ? 'active' : '' ?><!--" data-page="clients"><a href="/web/clients"><i class="fas fa-building"></i><span>Клиенты</span></a></li>-->
                <li class="<?= str_starts_with($currentPath, '/web/storages') ? 'active' : '' ?>" data-page="storages"><a href="/web/storages"><i class="fas fa-server"></i><span>Хранилища</span></a></li>
                <li class="<?= str_starts_with($currentPath, '/web/files') ? 'active' : '' ?>" data-page="files"><a href="/web/files"><i class="fas fa-folder-open"></i><span>Файлы</span></a></li>
                <li class="<?= str_starts_with($currentPath, '/web/analytics') ? 'active' : '' ?>" data-page="analytics"><a href="/web/analytics"><i class="fas fa-chart-line"></i><span>Аналитика</span></a></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <div class="user-info">
                <div class="avatar">AD</div>
                <div class="user-details">
                    <p class="name">Storage Admin</p>
                    <p class="role">Root access</p>
                </div>
                <a href="/web/auth/logout" class="sidebar-logout" data-logout title="Выйти">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar glass">
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input id="global-search" type="text" placeholder="Поиск клиентов, bucket, файлов...">
            </div>
            <div class="topbar-actions">
                <button class="icon-btn glass-btn" title="Уведомления"><i class="fas fa-bell"></i><span class="badge">4</span></button>
            </div>
        </header>

        <div id="content-area" class="content-area">
            <?php
            if (function_exists('wrContent')) {
                wrContent();
            } elseif ($contentView !== '') {
                require $resourceRoot . '/' . ltrim($contentView, '/') . '.php';
            }
            ?>
        </div>
    </main>
</div>

<div id="modal-container"></div>
<div id="alert-container"></div>

<script src="/static/js/main.js"></script>
<script src="/static/js/charts.js"></script>
</body>
</html>
