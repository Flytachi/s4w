<?php

declare(strict_types=1);

namespace Io\Scripts;

use Flytachi\Winter\Console\Inc\Cmd;
use Flytachi\Winter\Console\Inc\CmdCustom;
use Flytachi\Winter\K2\Kernel;
use Io\FileManager;
use Main\Repositories\InstanceRepository;

/**
 * Orphan folder GC.
 *
 * Идёт по storage/chest/* и удаляет папки, для которых нет соответствующего
 * instance в БД. Страховка от сбоев в InstanceDeleteJob между DB-delete и rmdir.
 *
 *   ./call script Io/Scripts/OrphanFolderGc
 *   ./call script Io/Scripts/OrphanFolderGc --dry-run
 */
class OrphanFolderGc extends CmdCustom
{
    public static string $title = 's4w: remove storage/chest folders without instance row';

    public function handle(): void
    {
        self::printTitle('OrphanFolderGc', 34);

        $dryRun = array_key_exists('dry-run', $this->args['options'] ?? []);
        $root = Kernel::$pathStorage . '/' . FileManager::ROOT_FOLDER;

        if (!is_dir($root)) {
            self::printInfo("Chest root does not exist: {$root}");
            self::printTitle('OrphanFolderGc', 34);
            return;
        }

        $checked = 0;
        $removed = 0;
        $skipped = 0;

        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }
            $checked++;
            $id = $entry->getFilename();

            // Папки именуются UUID инстанса. Лишние имена (например ручные)
            // не трогаем — могут быть служебные.
            if (!$this->looksLikeUuid($id)) {
                $skipped++;
                self::printInfo("Skip non-uuid folder: {$id}");
                continue;
            }

            $instance = InstanceRepository::findById($id);
            if ($instance !== null) {
                continue;
            }

            if ($dryRun) {
                self::printInfo("[dry-run] would remove: {$id}");
                $removed++;
                continue;
            }

            try {
                (new FileManager())->rmdir($id);
                self::printSuccess("Removed orphan folder: {$id}");
                $removed++;
            } catch (\Throwable $e) {
                self::printWarning("Failed to remove {$id}: {$e->getMessage()}");
            }
        }

        self::printDivider();
        self::printKeyValue('checked', (string) $checked);
        self::printKeyValue('removed', (string) $removed);
        self::printKeyValue('skipped', (string) $skipped);
        if ($dryRun) {
            self::printInfo('Dry-run mode — no changes applied.');
        }

        self::printTitle('OrphanFolderGc', 34);
    }

    private function looksLikeUuid(string $s): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $s,
        );
    }
}
