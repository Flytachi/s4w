<?php

declare(strict_types=1);

namespace Io\Scripts;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\Console\Inc\CmdCustom;
use Flytachi\Winter\K2\Kernel;
use Io\FileManager;
use Main\Repositories\FileBlobRepository;

/**
 * Orphan blob GC.
 *
 * Идёт по storage/chest/{instanceId}/{hash} и удаляет файлы-blob'ы, для которых
 * НЕТ строки в s4w_file_blobs (по instance_id + hash). Может образоваться при сбое
 * upload между FileManager::blobWrite и INSERT FileBlob. На уровень глубже
 * OrphanFolderGc (тот сносит целые папки несуществующих инстансов).
 *
 * Чтобы не задеть незавершённую параллельную загрузку — трогаем только файлы
 * старше MIN_AGE (по mtime).
 *
 *   php call sc io.scripts.orphanBlobGc
 *   php call sc io.scripts.orphanBlobGc --dry-run
 */
class OrphanBlobGc extends CmdCustom
{
    public static string $title = 's4w: remove storage/chest blob files without a FileBlob row';

    private const int MIN_AGE = 3600; // не трогаем файлы моложе часа (in-flight upload)

    public function handle(): void
    {
        self::printTitle('OrphanBlobGc', 34);

        $dryRun = array_key_exists('dry-run', $this->args['options'] ?? []);
        $root = Kernel::$pathStorage . '/' . FileManager::ROOT_FOLDER;

        if (!is_dir($root)) {
            self::printInfo("Chest root does not exist: {$root}");
            self::printTitle('OrphanBlobGc', 34);
            return;
        }

        $now = time();
        $checked = 0;
        $removed = 0;
        $skipped = 0;
        $freed = 0;

        foreach (new \DirectoryIterator($root) as $instDir) {
            if ($instDir->isDot() || !$instDir->isDir()) {
                continue;
            }
            $instanceId = $instDir->getFilename();
            if (!$this->looksLikeUuid($instanceId)) {
                continue; // не наша папка
            }

            foreach (new \DirectoryIterator($instDir->getPathname()) as $blobFile) {
                if ($blobFile->isDot() || !$blobFile->isFile()) {
                    continue;
                }
                $hash = $blobFile->getFilename();

                // имя blob'а — sha256 hex (64 симв.). Прочее не трогаем.
                if (!$this->looksLikeSha256($hash)) {
                    $skipped++;
                    self::printInfo("Skip non-hash file: {$instanceId}/{$hash}");
                    continue;
                }

                $checked++;

                // защита от гонки с незавершённой загрузкой
                if (($now - $blobFile->getMTime()) < self::MIN_AGE) {
                    $skipped++;
                    continue;
                }

                $blob = FileBlobRepository::instance()
                    ->where(Qb::and(
                        Qb::eq('instance_id', $instanceId),
                        Qb::eq('hash', $hash),
                    ))
                    ->find();
                if ($blob !== null) {
                    continue; // строка есть — валидный blob
                }

                $size = (int) ($blobFile->getSize() ?: 0);

                if ($dryRun) {
                    self::printInfo("[dry-run] would remove: {$instanceId}/{$hash} ({$size} B)");
                    $removed++;
                    $freed += $size;
                    continue;
                }

                try {
                    FileManager::blobDelete($instanceId, $hash);
                    self::printSuccess("Removed orphan blob: {$instanceId}/{$hash} ({$size} B)");
                    $removed++;
                    $freed += $size;
                } catch (\Throwable $e) {
                    self::printWarning("Failed to remove {$instanceId}/{$hash}: {$e->getMessage()}");
                }
            }
        }

        self::printDivider();
        self::printKeyValue('checked', (string) $checked);
        self::printKeyValue('removed', (string) $removed);
        self::printKeyValue('skipped', (string) $skipped);
        self::printKeyValue('freed bytes', (string) $freed);
        if ($dryRun) {
            self::printInfo('Dry-run mode — no changes applied.');
        }

        self::printTitle('OrphanBlobGc', 34);
    }

    private function looksLikeUuid(string $s): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $s,
        );
    }

    private function looksLikeSha256(string $s): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/i', $s);
    }
}
