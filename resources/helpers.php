<?php

function fs_percent(float|int $used, float|int $limit): int
{
    if ($limit <= 0) {
        return 0;
    }

    return min(100, (int) round(($used / $limit) * 100));
}

function fs_format_gb(float|int $value): string
{
    return number_format($value / 1024 / 1024, 1, '.', ' ') . ' MB';
}

function fs_demo_clients(): array
{
    return [
        ['id' => 'cl-1', 'name' => 'Aster Cloud', 'email' => 'ops@aster.cloud', 'status' => 'active', 'storages' => 2, 'usedGb' => 3120, 'limitGb' => 5200, 'requests' => 384000],
        ['id' => 'cl-2', 'name' => 'Nova Retail', 'email' => 'infra@nova.uz', 'status' => 'active', 'storages' => 1, 'usedGb' => 980, 'limitGb' => 1600, 'requests' => 126500],
        ['id' => 'cl-3', 'name' => 'Atlas Media', 'email' => 'media@atlas.io', 'status' => 'review', 'storages' => 2, 'usedGb' => 4450, 'limitGb' => 6800, 'requests' => 518200],
    ];
}

function fs_demo_storages(): array
{
    return [
        ['id' => 'st-1', 'clientId' => 'cl-1', 'name' => 'aster-prod-assets', 'region' => 'eu-central-1', 'limitGb' => 3200, 'usedGb' => 2280, 'frozen' => false, 'extensions' => ['jpg', 'png', 'webp', 'pdf', 'mp4'], 'objects' => 148200],
        ['id' => 'st-2', 'clientId' => 'cl-1', 'name' => 'aster-backups', 'region' => 'us-east-1', 'limitGb' => 2000, 'usedGb' => 840, 'frozen' => true, 'extensions' => ['zip', 'tar', 'sql', 'json'], 'objects' => 8740],
        ['id' => 'st-3', 'clientId' => 'cl-2', 'name' => 'nova-catalog', 'region' => 'ap-south-1', 'limitGb' => 1600, 'usedGb' => 980, 'frozen' => false, 'extensions' => ['jpg', 'png', 'csv', 'xlsx'], 'objects' => 36100],
        ['id' => 'st-4', 'clientId' => 'cl-3', 'name' => 'atlas-video-vault', 'region' => 'eu-west-2', 'limitGb' => 5600, 'usedGb' => 3910, 'frozen' => false, 'extensions' => ['mp4', 'mov', 'srt', 'jpg'], 'objects' => 19200],
        ['id' => 'st-5', 'clientId' => 'cl-3', 'name' => 'atlas-archive-freeze', 'region' => 'us-west-2', 'limitGb' => 1200, 'usedGb' => 540, 'frozen' => true, 'extensions' => ['zip', 'rar', 'pdf'], 'objects' => 4200],
    ];
}

function fs_client_name(array $clients, string $id): string
{
    foreach ($clients as $client) {
        if ($client['id'] === $id) {
            return $client['name'];
        }
    }

    return 'Unknown';
}

function fs_extension_tags(array $extensions): string
{
    return implode('', array_map(
        static fn(string $ext): string => '<span class="chip">.' . htmlspecialchars($ext) . '</span>',
        $extensions
    ));
}

