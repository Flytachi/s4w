<?php

namespace Io;

use Flytachi\Winter\K2\Kernel;

final class FileManager
{
    const string ROOT_FOLDER = 'chest';

    private string $currentPath;

    public function __construct()
    {
        $this->checkRoot();
    }

    public function mkdir(string $folderName): void
    {
        $this->currentPath .= '/' . $folderName;
        if (!is_dir($this->currentPath)) {
            if (!@mkdir($this->currentPath, 0777, true)) {
                throw new \RuntimeException("S4w: Folder \"{$this->currentPath}\" don't created");
            }
            @chmod($this->currentPath, 0777);
        }
    }

    public function rmdir(string $folderName): void
    {
        $this->currentPath .= '/' . $folderName;
        if (!is_dir($this->currentPath)) {
            return;
        }
        flushDirectory($this->currentPath, $this->currentPath);
        @rmdir($this->currentPath);
    }

    private function checkRoot(): void
    {
        $this->currentPath = Kernel::$pathStorage . '/' . self::ROOT_FOLDER;
        if (!is_dir($this->currentPath)) {
            if (!@mkdir($this->currentPath, 0777, true)) {
                throw new \RuntimeException("S4w: Root folder \"{$this->currentPath}\" don't created");
            }
            @chmod($this->currentPath, 0777);
        }
    }

    // ───────────────────────────────────────────────────────────────────────
    //  Blob API (CAS-style: chest/{instanceId}/{hash})
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Абсолютный путь к blob: storage/chest/{instanceId}/{hash}
     */
    public static function blobPath(string $instanceId, string $hash): string
    {
        return Kernel::$pathStorage . '/' . self::ROOT_FOLDER . '/' . $instanceId . '/' . $hash;
    }

    /**
     * Существует ли blob на диске.
     */
    public static function blobExists(string $instanceId, string $hash): bool
    {
        return is_file(self::blobPath($instanceId, $hash));
    }

    /**
     * Записать blob: переносит tmp-файл в финальное место (по возможности атомарно через rename).
     * Создаст папку инстанса, если её ещё нет.
     */
    public static function blobWrite(string $instanceId, string $hash, string $srcTmpPath): string
    {
        $dir = Kernel::$pathStorage . '/' . self::ROOT_FOLDER . '/' . $instanceId;
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            throw new \RuntimeException("S4w: Failed to create instance dir \"{$dir}\"");
        }
        $target = $dir . '/' . $hash;
        if (!@rename($srcTmpPath, $target)) {
            throw new \RuntimeException("S4w: Failed to store blob at \"{$target}\"");
        }
        @chmod($target, 0666);
        return $target;
    }

    /**
     * Прочитать blob целиком в строку.
     */
    public static function blobRead(string $instanceId, string $hash): string
    {
        $path = self::blobPath($instanceId, $hash);
        if (!is_file($path)) {
            throw new \RuntimeException("S4w: Blob \"{$path}\" not found");
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("S4w: Failed to read blob at \"{$path}\"");
        }
        return $content;
    }

    /**
     * Удалить blob с диска. Безопасно вызывать на несуществующем (no-op).
     */
    public static function blobDelete(string $instanceId, string $hash): void
    {
        $path = self::blobPath($instanceId, $hash);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
