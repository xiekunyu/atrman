<?php

namespace app\service;

use app\cache\ReflectionCache;
use app\exception\ApiException;
use app\model\system\File;
use app\utils\Directory;
use ZipArchive;

class FileService
{
    public const MANIFEST_FILE = 'manifest.json';
    public const MAX_LOGO_SIZE = 2097152;
    public const MAX_PACKAGE_FILES = 5000;
    public const MAX_PACKAGE_FILE_SIZE = 52428800;
    public const MAX_PACKAGE_UNCOMPRESSED_SIZE = 104857600;

    public static function pluginRoot(): string
    {
        return base_path()  . '/plugin';
    }

    public static function widgetRoute(string $pluginName): string
    {
        return 'app' . '/' . $pluginName . '/' . 'widget';
    }

    public static function widgetPublicRoot(string $pluginName): string
    {
        return self::pluginRoot() . '/' . $pluginName . '/public/widget';
    }

    public static function widgetExtension(int $cate): string
    {
        return match ($cate) {
            1 => 'vue',
            2 => 'html',
            default => throw new ApiException('小组件类型不正确'),
        };
    }

    public static function widgetFileName(string $name, int $cate): string
    {
        return $name . '.' . self::widgetExtension($cate);
    }

    public static function widgetUrl(string $pluginName, string $name, int $cate): string
    {
        return self::widgetRoute($pluginName) . '/' . self::widgetFileName($name, $cate);
    }

    public static function ensureWidgetFile(string $pluginName, string $name, int $cate): string
    {
        PluginManifestService::validateManifest([
            'name' => $pluginName,
            'title' => $pluginName,
            'version' => '1.0.0',
        ]);
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $name)) {
            throw new ApiException('小组件标识格式不正确');
        }

        $root = self::widgetPublicRoot($pluginName);
        Directory::ensureDir($root);
        $basePath = realpath($root);
        if (!$basePath) {
            throw new ApiException('小组件目录创建失败');
        }

        $filePath = $basePath . DIRECTORY_SEPARATOR . self::widgetFileName($name, $cate);
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        if (strpos($normalized, $basePath . DIRECTORY_SEPARATOR) !== 0) {
            throw new ApiException('小组件文件路径不正确');
        }
        if (!is_file($normalized)) {
            self::writePluginFile($normalized, '');
        }
        return $normalized;
    }

    public static function pluginPackageRoot(): string
    {
        return runtime_path() . '/plugin_packages';
    }

    public static function pluginBackupRoot(): string
    {
        return runtime_path() . '/plugin_backups';
    }

    public static function validateZipEntryName(string $name): void
    {
        $normalized = str_replace('\\', '/', $name);
        if ($normalized === '') {
            throw new ApiException('安装包内包含空文件名');
        }
        if (str_contains($normalized, "\0")) {
            throw new ApiException('安装包内包含非法文件名');
        }
        if ($normalized[0] === '/') {
            throw new ApiException('安装包内包含绝对路径：' . $name);
        }
        if (preg_match('/^[A-Za-z]:/', $normalized)) {
            throw new ApiException('安装包内包含盘符路径：' . $name);
        }
        if (str_contains($normalized, '://')) {
            throw new ApiException('安装包内包含非法协议路径：' . $name);
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new ApiException('安装包内包含目录穿越：' . $name);
            }
        }
    }

    public static function extractZipSafely(string $zipPath, string $targetDir): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ApiException('安装包打开失败');
        }

        $totalSize = 0;
        $entryCount = $zip->numFiles;
        if ($entryCount > self::MAX_PACKAGE_FILES) {
            $zip->close();
            throw new ApiException('安装包内文件数量超过限制：' . $entryCount);
        }

        for ($i = 0; $i < $entryCount; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $entryName = (string)($stat['name'] ?? '');
            self::validateZipEntryName($entryName);

            if ($stat['size'] > self::MAX_PACKAGE_FILE_SIZE) {
                $zip->close();
                throw new ApiException('安装包内文件大小超过限制：' . $entryName);
            }
            $totalSize += $stat['size'];
            if ($totalSize > self::MAX_PACKAGE_UNCOMPRESSED_SIZE) {
                $zip->close();
                throw new ApiException('安装包解压总大小超过限制');
            }

            $entryNameNormalized = str_replace('\\', '/', $entryName);
            $targetPath = $targetDir . '/' . ltrim($entryNameNormalized, '/');
            $targetPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetPath);

            if (str_ends_with($entryNameNormalized, '/')) {
                Directory::ensureDir($targetPath);
            } else {
                Directory::ensureDir(dirname($targetPath));
                if (!@copy('zip://' . $zipPath . '#' . $entryName, $targetPath)) {
                    $zip->close();
                    throw new ApiException('安装包文件解压失败：' . $entryName);
                }
            }
        }

        $zip->close();
    }

    public static function findPackageSourceDir(string $extractDir): string
    {
        $dir = Directory::findDirWithFile($extractDir, self::MANIFEST_FILE);
        if ($dir === null) {
            throw new ApiException('安装包中未找到 ' . self::MANIFEST_FILE);
        }
        return $dir;
    }

    public static function backupPluginDir(string $targetDir, string $pluginName): string
    {
        $backupRoot = self::pluginBackupRoot();
        Directory::ensureDir($backupRoot);
        $backupDir = $backupRoot . '/' . $pluginName . '_' . date('YmdHis') . '_' . uniqid();
        Directory::moveDir($targetDir, $backupDir);
        return $backupDir;
    }

    public static function restorePluginBackup(string $backupDir, string $targetDir): void
    {
        if (!is_dir($backupDir)) {
            return;
        }
        if (is_dir($targetDir)) {
            Directory::removeDirWithin($targetDir, self::pluginRoot());
        }
        Directory::moveDir($backupDir, $targetDir);
    }

    public static function removePluginFiles(string $name): void
    {
        PluginManifestService::validateManifest([
            'name' => $name,
            'title' => $name,
            'version' => '1.0.0',
        ]);
        $dir = self::pluginRoot() . '/' . $name;
        if (is_dir($dir)) {
            Directory::removeDirWithin($dir, self::pluginRoot());
        }
    }

    public static function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            $count += is_dir($path) ? self::countFiles($path) : 1;
        }
        return $count;
    }

    /**
     * 解析自建插件的入口声明：调用方显式携带键时忠实采用（空串=纯插件无页面入口），
     * 未携带键时沿用脚手架默认约定 /app/{name} 与 /app/{name}/manage。
     *
     * @return array{0:string,1:string}
     */
    protected static function resolveCustomEntries(array $plugin): array
    {
        $name = (string)($plugin['name'] ?? '');
        $entry = array_key_exists('entry', $plugin) ? trim((string)$plugin['entry']) : '/app/' . $name;
        $manageEntry = array_key_exists('manage_entry', $plugin)
            ? trim((string)$plugin['manage_entry'])
            : ($entry !== '' ? $entry . '/manage' : '');

        return [$entry, $manageEntry];
    }

    /** 自建插件需要渲染的桩文件清单：无页面入口（纯插件）时不生成路由与视图文件。 */
    protected static function customPluginStubFiles(string $entry): array
    {
        $files = [
            'manifest.json.stub' => self::MANIFEST_FILE,
            'Plugin.php.stub' => 'Plugin.php',
            'install.sql.stub' => 'install.sql',
            'config/app.php.stub' => 'config/app.php',
            'config/view.php.stub' => 'config/view.php',
            'config/static.php.stub' => 'config/static.php',
            'public/widget/AppWidget.vue.stub' => 'public/widget/AppWidget.vue',
        ];
        if ($entry === '') {
            return $files;
        }

        return array_merge($files, [
            'config/route.php.stub' => 'config/route.php',
            'app/controller/AppController.php.stub' => 'app/controller/AppController.php',
            'app/view/app/index.html.stub' => 'app/view/app/index.html',
            'app/view/app/manage.html.stub' => 'app/view/app/manage.html',
        ]);
    }

    /** 构建自建插件的落盘 manifest（入口声明忠实保留，空串表示纯插件）。 */
    protected static function buildCustomPluginManifest(array $plugin, array $entries): array
    {
        $name = (string)($plugin['name'] ?? '');
        $manifest = PluginManifestService::completeManifest(array_merge($plugin, [
            'entry' => $entries[0],
            'manage_entry' => $entries[1],
        ]));

        return array_merge($manifest, [
            'path' => 'plugin/' . $name,
            'view_root' => 'view',
            'is_custom' => true,
            'hook' => 'plugin\\' . $name . '\\Plugin',
        ]);
    }

    public static function scaffoldCustomPlugin(array $plugin): void
    {
        $entries = self::resolveCustomEntries($plugin);
        $plugin = PluginManifestService::completeManifest(array_merge($plugin, [
            'entry' => $entries[0],
            'manage_entry' => $entries[1],
        ]));
        $targetDir = self::pluginRoot() . '/' . $plugin['name'];
        if (is_dir($targetDir)) {
            throw new ApiException('插件目录已存在：' . $plugin['name']);
        }

        // webman 应用插件 url 路径默认以 /app 开头，接口与入口统一命名空间：$entry
        $manifest = self::buildCustomPluginManifest($plugin, $entries);

        try {
            self::renderCustomPluginScaffold($targetDir, $plugin, $manifest, $entries[0]);
        } catch (\Throwable $e) {
            Directory::removeDirWithin($targetDir, self::pluginRoot());
            throw $e;
        }
    }

    public static function ensureCustomPluginScaffold(array $plugin): void
    {
        $name = (string)($plugin['name'] ?? '');
        if ($name !== '' && is_file(self::pluginRoot() . '/' . $name . '/' . self::MANIFEST_FILE)) {
            return;
        }

        self::scaffoldCustomPlugin($plugin);
    }

    public static function regenerateCustomPluginScaffold(array $plugin): void
    {
        $name = (string)($plugin['name'] ?? '');
        $entries = self::resolveCustomEntries($plugin);
        $manifest = self::buildCustomPluginManifest($plugin, $entries);

        $targetDir = self::pluginRoot() . '/' . $name;

        self::renderCustomPluginScaffold($targetDir, $manifest, $manifest, $entries[0], false);

        // 重生成可能改变控制器白名单/注解（ReflectionCache 按控制器进程内备忘），必须即时失效
        ReflectionCache::clear();
    }

    public static function syncManifestFile(array $manifest): void
    {
        $manifest = PluginManifestService::completeManifest($manifest);
        $path = self::pluginRoot() . '/' . $manifest['name'] . '/' . self::MANIFEST_FILE;
        self::writePluginFile($path, \jsonEncodePretty($manifest));
    }

    /**
     * 生成插件安装包。
     *
     * @param array $overrides 仅作用于包内 manifest.json 的发布形态字段（入口、是否核心应用），
     *                         源目录保持开发态，使正在调试的配置与生成的包互不影响。
     */
    public static function buildPackage(string $name, array $overrides = []): array
    {
        PluginManifestService::validateManifest([
            'name' => $name,
            'title' => $name,
            'version' => '1.0.0',
        ]);
        if (!class_exists(ZipArchive::class)) {
            throw new ApiException('当前 PHP 环境未安装 ZipArchive 扩展');
        }

        $sourceDir = self::pluginRoot() . '/' . $name;
        if (!is_file($sourceDir . '/' . self::MANIFEST_FILE)) {
            throw new ApiException('插件目录不存在或缺少配置文件：' . $name);
        }
        DatabaseService::cleanInstallSql($sourceDir);
        $installSqlPath = $sourceDir . '/' . DatabaseService::INSTALL_SQL_FILE;
        if (!is_file($installSqlPath)) {
            throw new ApiException('插件安装 SQL 不存在：' . $installSqlPath);
        }
        $targetDir = runtime_path() . '/plugin_packages';
        Directory::ensureDir($targetDir);
        $zipPath = $targetDir . '/' . $name . '_' . date('YmdHis') . '.zip';

        // 发布包以正式应用形态分发：开发/自建标记只应存在于本地开发环境。
        // 覆写包内 manifest.json（安装端 is_dev 直接取该值，is_custom 亦随包归零），
        // 源目录保持不变——开发者无需为打包而关掉 is_dev（否则“生成安装包”按钮会消失）。
        $packageManifest = json_decode((string)file_get_contents($sourceDir . '/' . self::MANIFEST_FILE), true);
        if (!is_array($packageManifest)) {
            throw new ApiException('插件 manifest.json 解析失败：' . $name);
        }
        $packageManifest['is_dev'] = 0;
        $packageManifest['is_custom'] = 0;

        // 入口与核心标记属于发布形态：本地开发入口（dev server 地址等）不得固化进安装包。
        // 空串是有效值（纯插件无页面入口），故按“是否提交该字段”判定，不做非空过滤。
        foreach (['entry', 'manage_entry'] as $field) {
            if (array_key_exists($field, $overrides)) {
                $packageManifest[$field] = trim((string)$overrides[$field]);
            }
        }
        if (array_key_exists('is_core', $overrides)) {
            $packageManifest['is_core'] = (int)$overrides['is_core'] === 1 ? 1 : 0;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ApiException('插件安装包创建失败');
        }
        Directory::addDirToZip($zip, $sourceDir, '', [self::MANIFEST_FILE]);
        $zip->addFromString(self::MANIFEST_FILE, \jsonEncodePretty($packageManifest));
        $zip->close();

        return [
            'path' => $zipPath,
            'name' => basename($zipPath),
        ];
    }

    public static function removeCustomPluginFiles(string $name): void
    {
        PluginManifestService::validateManifest([
            'name' => $name,
            'title' => $name,
            'version' => '1.0.0',
        ]);
        Directory::removeDirWithin(self::pluginRoot() . '/' . $name, self::pluginRoot());
    }

    public static function normalizeIcon(string $icon, string $pluginName): string
    {
        $icon = trim($icon);
        if ($icon === '') {
            return self::pluginPublicAssetUrl($pluginName);
        }
        $oldPublicPrefix = '/plugin/' . $pluginName . '/public/';
        if (str_starts_with($icon, $oldPublicPrefix)) {
            return self::pluginPublicAssetUrl($pluginName, substr($icon, strlen($oldPublicPrefix)) ?: 'logo.png');
        }
        if (preg_match('/^(https?:)?\/\//i', $icon) || str_starts_with($icon, '/') || preg_match('/^(data:image\/|blob:)/i', $icon)) {
            return $icon;
        }
        if (str_contains($icon, ':') && !preg_match('/\.(png|jpe?g|gif|webp|svg|ico)(\?.*)?$/i', $icon)) {
            return $icon;
        }

        $path = preg_replace('#^public/#', '', ltrim($icon, '/'));
        return self::pluginPublicAssetUrl($pluginName, $path ?: 'logo.png');
    }

    public static function pluginPublicAssetUrl(string $pluginName, string $path = 'logo.png', bool $absolute = false): string
    {
        $url = '/app/' . rawurlencode($pluginName) . '/' . ltrim($path, '/');
        return $absolute ? \assetDomain() . $url : $url;
    }

    public static function withAssetDomain(string $url): string
    {
        return \withAssetDomain($url);
    }

    public static function saveCustomPluginLogo(string $pluginName, string $sourceIcon): string
    {
        $sourceIcon = trim($sourceIcon);
        $logoUrl = self::pluginPublicAssetUrl($pluginName);
        if ($sourceIcon === '' || self::isSamePluginLogo($sourceIcon, $pluginName)) {
            return $logoUrl;
        }
        if (preg_match('/^(blob:|data:|file:)/i', $sourceIcon)) {
            throw new ApiException('插件图标地址不支持该协议');
        }

        $sourceIcon = \normalizeDownloadUrl($sourceIcon);
        // 校验得到的公网 IP 同时作为固定连接目标，避免解析后 DNS 改指内网
        $pinnedIp = \assertPublicUrl($sourceIcon);
        $tempFile = \createTempFile($sourceIcon, 'png', false, $pinnedIp);
        if (!$tempFile) {
            throw new ApiException('插件图标下载失败');
        }

        try {
            return self::saveLogoFromLocalPath($tempFile, $pluginName);
        } catch (\Exception $e) {
            throw new ApiException($e->getMessage());
        } finally {
            is_file($tempFile) && @unlink($tempFile);
        }
    }

    /** 以文件库记录为来源保存插件图标：本地文件直读磁盘复制，远程存储回退为 URL 下载。 */
    public static function saveCustomPluginLogoFromFile(File $file, string $pluginName): string
    {
        $source = self::resolveLocalFilePath($file);
        if ($source === null) {
            // OSS/COS 等远程存储没有本地文件，回退为原有 URL 下载流程
            return self::saveCustomPluginLogo($pluginName, trim((string)($file->url ?: '') . (string)$file->path));
        }

        return self::saveLogoFromLocalPath($source, $pluginName);
    }

    /** 从文件库记录解析本地磁盘路径，解析不到视为远程存储返回 null。 */
    public static function resolveLocalFilePath(File $file): ?string
    {
        $relativePath = str_replace('\\', '/', ltrim((string)$file->path, '/'));
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '..') || preg_match('/^[A-Za-z]:/', $relativePath)) {
            return null;
        }
        $relativePath = str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        foreach ([public_path(), base_path()] as $root) {
            $candidate = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;
            if (is_file($candidate)) {
                return Directory::assertPathWithin($candidate, $root);
            }
        }
        return null;
    }

    /** 校验插件图标目标目录与文件可写，失败时给出含路径的权限提示。 */
    protected static function assertLogoTargetWritable(string $target): void
    {
        $dir = dirname($target);
        if (is_dir($dir) && !is_writable($dir)) {
            throw new ApiException('插件图标目录不可写，请检查目录权限与属主（应与 Webman 运行用户一致）：' . $dir);
        }
        if (is_file($target) && !is_writable($target)) {
            throw new ApiException('插件图标文件不可写，请检查文件权限与属主（应与 Webman 运行用户一致）：' . $target);
        }
    }

    /** 从本地文件路径保存插件图标：大小检查 → 图片验证 → 目录创建 → 复制 → 返回 URL。 */
    private static function saveLogoFromLocalPath(string $sourcePath, string $pluginName): string
    {
        $logoUrl = self::pluginPublicAssetUrl($pluginName);
        if (filesize($sourcePath) > self::MAX_LOGO_SIZE) {
            throw new ApiException('插件图标不能超过 2MB');
        }
        \assertImageFile($sourcePath);
        $target = self::pluginPublicAssetPath($pluginName, 'logo.png');
        Directory::ensureDir(dirname($target));
        self::assertLogoTargetWritable($target);
        if (!@copy($sourcePath, $target)) {
            throw new ApiException('插件图标保存失败：' . $target);
        }
        return $logoUrl;
    }

    public static function pluginPublicAssetPath(string $pluginName, string $path): string
    {
        return self::pluginRoot() . '/' . $pluginName . '/public/' . ltrim($path, '/');
    }

    public static function isSamePluginLogo(string $url, string $pluginName): bool
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
        return $path === self::pluginPublicAssetUrl($pluginName)
            || $path === '/app/' . $pluginName . '/logo.png';
    }

    public static function renderCustomPluginScaffold(string $targetDir, array $plugin, array $manifest, string $entry, bool $overwrite = true): void
    {
        $variables = self::pluginStubVariables($plugin, $manifest, $entry);
        $files = self::customPluginStubFiles($entry);

        foreach ($files as $stub => $target) {
            $targetPath = $targetDir . '/' . $target;
            if (!$overwrite && is_file($targetPath)) {
                continue;
            }
            self::writePluginStubFile($stub, $targetPath, $variables);
        }
        if (!is_file($targetDir . '/' . self::MANIFEST_FILE)) {
            throw new ApiException('插件模板生成失败：' . $plugin['name']);
        }
    }

    public static function writePluginFile(string $path, string $content): void
    {
        Directory::ensureDir(dirname($path));
        if (file_put_contents($path, $content) === false) {
            throw new ApiException('插件文件写入失败：' . $path);
        }
    }

    public static function writePluginStubFile(string $stub, string $target, array $variables): void
    {
        $path = self::pluginStubRoot() . '/' . $stub;
        if (!is_file($path)) {
            throw new ApiException('插件模板文件不存在：' . $stub);
        }

        self::writePluginFile($target, strtr(file_get_contents($path) ?: '', $variables));
    }

    public static function pluginStubVariables(array $plugin, array $manifest, string $entry): array
    {
        $title = (string)$plugin['title'];
        $name = (string)$plugin['name'];
        return [
            '{{name}}' => $name,
            '{{title}}' => $title,
            '{{title_php}}' => addcslashes($title, "\\'"),
            '{{title_html}}' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            '{{title_js}}' => addcslashes($title, "\\'"),
            '{{entry}}' => $entry,
            // api_base：插件物理路径（public 资源挂载路径），与 url 路由前缀 {{entry}}(/app/{name}) 不同
            '{{api_base}}' => '/plugin/' . $name,
            '{{component_name}}' => str_replace(' ', '', ucwords(str_replace('_', ' ', $name))) . 'Widget',
            '{{manifest_json}}' => \jsonEncodePretty($manifest),
            // 安装期占位符：入口菜单地址（不含记录 id，迁移其他实例后仍有效）
            '{{APP_ENTRY_PATH}}' => PluginManager::entryMenuPath($name),
        ];
    }

    public static function pluginStubRoot(): string
    {
        return base_path() . '/stub/plugin';
    }

    public static function pluginRootFromPath(string $path, string $name): string
    {
        $root = trim($path, '/');
        return $root === '' ? self::pluginRoot() . '/' . $name : base_path() . '/' . $root;
    }


    /** 压缩目录。 */
    public static function compressDirectory(string $sourceDir, string $zipPath, string $prefix = ''): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ApiException('压缩包创建失败：' . $zipPath);
        }
        Directory::addDirToZip($zip, $sourceDir, $prefix);
        $zip->close();
    }
}
