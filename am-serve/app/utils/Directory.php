<?php

namespace app\utils;

use app\exception\ApiException;
use ZipArchive;

class Directory
{
    /** 确保目录存在。 */
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiException('目录创建失败：' . $dir);
        }
    }

    /** 递归删除目录。 */
    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** 在指定根目录内安全删除目录。 */
    public static function removeDirWithin(string $dir, string $root): void
    {
        if (!is_dir($dir)) {
            return;
        }
        self::assertPathWithin($dir, $root, false);
        self::removeDir($dir);
    }

    /** 校验路径位于指定根目录内。 */
    public static function assertPathWithin(string $path, string $root, bool $allowRoot = true): string
    {
        $rootPath = realpath($root);
        if (!$rootPath || !is_dir($rootPath)) {
            throw new ApiException('根目录不存在：' . $root);
        }

        $targetPath = realpath($path);
        if (!$targetPath) {
            $targetPath = self::normalizePath($path);
            $rootPath = self::normalizePath($rootPath);
            $parent = self::normalizePath(dirname($targetPath));
            if (strpos($parent . DIRECTORY_SEPARATOR, $rootPath . DIRECTORY_SEPARATOR) !== 0 && $parent !== $rootPath) {
                throw new ApiException('路径不在允许目录内：' . $path);
            }
            return $targetPath;
        }

        $targetPath = self::normalizePath($targetPath);
        $rootPath = self::normalizePath($rootPath);
        if (!$allowRoot && $targetPath === $rootPath) {
            throw new ApiException('不允许操作根目录：' . $root);
        }
        if ($targetPath !== $rootPath && strpos($targetPath . DIRECTORY_SEPARATOR, $rootPath . DIRECTORY_SEPARATOR) !== 0) {
            throw new ApiException('路径不在允许目录内：' . $path);
        }
        return $targetPath;
    }

    /** 规范化本地路径分隔符。 */
    protected static function normalizePath(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    /** 递归复制目录。 */
    public static function copyDir(string $source, string $target, array $exclude = []): void
    {
        self::ensureDir($target);
        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (in_array($item, $exclude, true)) {
                continue;
            }
            $from = $source . '/' . $item;
            $to = $target . '/' . $item;
            is_dir($from) ? self::copyDir($from, $to, $exclude) : copy($from, $to);
        }
    }

    /** 移动目录，跨磁盘时降级为复制。 */
    public static function moveDir(string $source, string $target): void
    {
        if (!@rename($source, $target)) {
            self::copyDir($source, $target);
            self::removeDir($source);
        }
    }

    /** 递归添加目录到 zip。$exclude 为需跳过的包内相对路径（如根目录 manifest.json，交由调用方另行写入覆写内容）。 */
    public static function addDirToZip(ZipArchive $zip, string $source, string $prefix = '', array $exclude = []): void
    {
        foreach (scandir($source) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $source . '/' . $item;
            $localName = ltrim($prefix . '/' . $item, '/');
            if (in_array($localName, $exclude, true)) {
                continue;
            }
            if (is_dir($path)) {
                $zip->addEmptyDir($localName);
                self::addDirToZip($zip, $path, $localName, $exclude);
                continue;
            }
            $zip->addFile($path, $localName);
        }
    }

    /** 查找包含指定文件的目录。 */
    public static function findDirWithFile(string $root, string $fileName): ?string
    {
        if (is_file($root . '/' . $fileName)) {
            return $root;
        }
        $dirs = array_values(array_filter(glob($root . '/*') ?: [], 'is_dir'));
        foreach ($dirs as $dir) {
            if (is_file($dir . '/' . $fileName)) {
                return $dir;
            }
        }
        return null;
    }
}
