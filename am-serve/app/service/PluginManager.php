<?php

namespace app\service;

use app\basic\EventRegistry;
use app\cache\CacheManager;
use app\cache\UserMenuCache;
use app\exception\ApiException;
use app\model\plugin\App as Plugin;
use app\model\system\Backup;
use app\model\system\Menu;
use app\utils\Directory;
use app\utils\Security;
use support\Log;
use support\Redis;
use support\think\Db;
use ZipArchive;

class PluginManager
{
    /** 插件升级串行锁键（市场级联升级与本地安装包升级共用一把，全局串行化） */
    protected const UPGRADE_LOCK_KEY = 'atrman:plugin:cascade:lock';

    /** 升级锁 TTL（秒） */
    protected const UPGRADE_LOCK_TTL = 900;

    /**
     * 验证插件包文件并创建工作目录
     * 
     * @return array [workDir, packageRoot, extractDir]
     */
    private static function validateAndPreparePackage(string $zipPath, string $workPrefix = 'pkg_', string $fileNotFoundMsg = '安装包不存在'): array
    {
        if (!is_file($zipPath)) {
            throw new ApiException($fileNotFoundMsg);
        }
        if (strtolower(pathinfo($zipPath, PATHINFO_EXTENSION)) !== 'zip') {
            throw new ApiException('仅支持 zip 格式文件');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new ApiException('当前 PHP 环境未安装 ZipArchive 扩展');
        }

        $packageRoot = FileService::pluginPackageRoot();
        Directory::ensureDir($packageRoot);
        $workDir = $packageRoot . '/' . uniqid($workPrefix, true);
        $extractDir = $workDir . '/extract';
        Directory::ensureDir($extractDir);

        return [$workDir, $packageRoot, $extractDir];
    }

    /** 扫描本地插件目录并同步数据库。 */
    public static function scan(): array
    {
        $pluginRoot = FileService::pluginRoot();
        if (!is_dir($pluginRoot)) {
            return [];
        }

        $plugins = [];
        foreach (scandir($pluginRoot) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $manifestPath = $pluginRoot . '/' . $item . '/' . FileService::MANIFEST_FILE;
            if (!is_file($manifestPath)) {
                continue;
            }

            // 单个插件异常（manifest 解析失败、目录名不一致等）只跳过该插件，不阻断整体扫描
            try {
                $manifest = PluginManifestService::readManifest($manifestPath);
                if ($manifest['name'] !== $item) {
                    throw new ApiException('插件目录名必须与插件标识一致：' . $manifest['name']);
                }
                $manifest['path'] = 'plugin/' . $item;
                $manifest = PluginManifestService::completeManifest($manifest);
                $plugin = Plugin::where('name', $manifest['name'])->findOrEmpty();
                if (PluginManifestService::shouldSkipScan($manifest, $plugin)) {
                    continue;
                }
                $plugin = PluginManifestService::syncPlugin($manifest, $plugin);
                // 存量回填：已安装插件补齐/同步"管理入口"顶级菜单（幂等）
                if (in_array((int)$plugin->status, [0, 1], true)) {
                    self::syncEntry($plugin);
                }
                $plugins[] = $plugin->toArray();
            } catch (\Throwable $e) {
                Log::error('插件扫描跳过 [' . $item . ']：' . $e->getMessage());
            }
        }

        return $plugins;
    }


    /** 恢复插件数据库记录快照。 */
    protected static function restorePluginSnapshot(string $pluginName, ?array $snapshot): void
    {
        if ($snapshot === null) {
            $record = Plugin::where('name', $pluginName)->find();
            if ($record && !$record->isEmpty()) {
                $record->delete();
            }
            return;
        }
        $record = Plugin::where('name', $pluginName)->find();
        if ($record && !$record->isEmpty()) {
            $record->save($snapshot);
        }
    }

    /** 解压并安装插件包。 */
    public static function installPackage(string $zipPath, bool $overwrite = false): Plugin
    {
        [$workDir, $packageRoot, $extractDir] = self::validateAndPreparePackage($zipPath, 'pkg_');
        $stagingDir = $workDir . '/staging';
        Directory::ensureDir($stagingDir);

        $backupDir = null;
        $oldSnapshot = null;
        $targetDir = '';
        $pluginName = '';
        $completed = false;
        $targetReplaced = false;

        try {
            FileService::extractZipSafely($zipPath, $extractDir);
            $sourceDir = FileService::findPackageSourceDir($extractDir);
            $manifest = PluginManifestService::completeManifest(PluginManifestService::readManifest($sourceDir . '/' . FileService::MANIFEST_FILE));
            $manifest['is_custom'] = 0;
            $pluginName = $manifest['name'];
            // 目标目录名取自包内自报的 manifest.name，先拦基座兜底保留名：
            // 这些名字属基座自身模块，市场侧根本不允许应用占用，撞上一律视为异常包
            if (PluginManifestService::isReservedName((string)$pluginName)) {
                throw new ApiException('插件标识占用系统保留名，拒绝安装：' . $pluginName);
            }
            $targetDir = FileService::pluginRoot() . '/' . $pluginName;
            $stagedDir = $stagingDir . '/' . $pluginName;

            PluginManifestService::checkRequirements($manifest);
            $oldPlugin = Plugin::where('name', $pluginName)->find();
            $oldSnapshot = $oldPlugin ? $oldPlugin->toArray() : null;
            $operation = self::resolvePackageOperation($oldPlugin, $manifest);
            if (($operation !== 'install' || is_dir($targetDir)) && !$overwrite) {
                throw new ApiException('插件已存在，如需覆盖请开启覆盖安装');
            }
            // 启用态不再是阻断项：调用方（runUpgrade / installPackageAutoDisable）负责
            // 「自动停用 → 覆盖安装 → 逆序恢复启用」，本方法只保证文件与库记录换到位。

            Directory::ensureDir(dirname($targetDir));
            Directory::moveDir($sourceDir, $stagedDir);
            if (is_dir($targetDir)) {
                $backupDir = FileService::backupPluginDir($targetDir, $pluginName);
            }
            Directory::moveDir($stagedDir, $targetDir);
            $targetReplaced = true;

            $manifest['path'] = 'plugin/' . $pluginName;
            $manifest['package_name'] = basename($zipPath);
            // 不再写 package_hash：全代码库无读取方，留着只会在审计时让人误以为做过比对

            if ($operation === 'install') {
                $plugin = PluginManifestService::syncPlugin($manifest);
                $plugin->status = 2;
                $plugin->save();
                self::install($plugin->name);
            } elseif ($operation === 'update') {
                $plugin = self::upgradeInstalledPlugin($oldPlugin, $manifest, (string)$oldPlugin->version, (string)$manifest['version'], $targetDir);
            } else {
                $plugin = self::overwriteSameVersionPlugin($oldPlugin, $manifest);
            }
            $completed = true;
        } catch (\Throwable $e) {
            if ($targetReplaced && $backupDir) {
                FileService::restorePluginBackup($backupDir, $targetDir);
            } elseif ($targetReplaced && $targetDir !== '' && is_dir($targetDir)) {
                Directory::removeDirWithin($targetDir, FileService::pluginRoot());
            }
            if ($pluginName !== '') {
                self::restorePluginSnapshot($pluginName, $oldSnapshot);
            }
            throw $e;
        } finally {
            if ($completed && $backupDir && is_dir($backupDir)) {
                Directory::removeDirWithin($backupDir, FileService::pluginBackupRoot());
            }
            if (is_dir($workDir)) {
                Directory::removeDirWithin($workDir, $packageRoot);
            }
        }

        return self::getPlugin($pluginName);
    }

    /** 预检插件安装包。 */
    public static function inspectPackage(string $zipPath): array
    {
        [$workDir, $packageRoot, $extractDir] = self::validateAndPreparePackage($zipPath, 'inspect_');

        try {
            FileService::extractZipSafely($zipPath, $extractDir);
            $sourceDir = FileService::findPackageSourceDir($extractDir);
            $manifest = PluginManifestService::completeManifest(PluginManifestService::readManifest($sourceDir . '/' . FileService::MANIFEST_FILE));
            $manifest['is_custom'] = 0;
            $pluginName = $manifest['name'];
            $targetDir = FileService::pluginRoot() . '/' . $pluginName;
            $oldPlugin = Plugin::where('name', $pluginName)->find();
            $hasRecord = (bool)$oldPlugin;
            $hasDir = is_dir($targetDir);
            $currentVersion = $oldPlugin ? (string)$oldPlugin->version : '';
            $targetVersion = (string)$manifest['version'];
            $operation = 'install';
            $migrationInfo = BackupService::buildMigrationInfo($pluginName, $currentVersion, $targetVersion, $sourceDir);
            $compatibility = PluginManifestService::getCompatibilityInfo($manifest);
            $dependencies = PluginManifestService::getDependencyInfo($manifest, false);
            $errors = [];
            $warnings = [];

            try {
                $operation = self::resolvePackageOperation($oldPlugin, $manifest);
            } catch (ApiException $e) {
                $operation = 'downgrade';
                $errors[] = $e->getMessage();
            }
            if (!$compatibility['compatible']) {
                $errors[] = $compatibility['message'];
            }
            foreach ($dependencies as $dependency) {
                if (!$dependency['satisfied']) {
                    $errors[] = $dependency['message'];
                }
            }
            // 启用中不再是阻断项：升级会自动停用、装完自动恢复（见 runUpgrade）。
            // 这里只产出「本次将临时停用哪些插件」的结构化清单给前端展示；依赖成环编排不了才算真阻断。
            $upgrade = self::preflightUpgrade($pluginName, $oldPlugin);
            if (!$upgrade['can_upgrade']) {
                $errors[] = $upgrade['block_reason'];
            }
            if ($operation === 'install') {
                $migrationInfo = BackupService::buildMigrationInfo($pluginName, $targetVersion, $targetVersion, $sourceDir);
            } elseif ($operation === 'update' && $migrationInfo['pending_count'] > 0) {
                $warnings[] = '本次升级将执行 ' . (int)$migrationInfo['pending_count'] . ' 条待迁移 SQL，请先备份数据库';
            }
            return [
                'manifest' => $manifest,
                'plugin' => [
                    'name' => $pluginName,
                    'title' => $manifest['title'],
                    'version' => $manifest['version'],
                    'icon' => $manifest['icon'] ?? '',
                ],
                'package' => [
                    'name' => basename($zipPath),
                    'hash' => hash_file('sha256', $zipPath),
                ],
                'existing' => [
                    'has_record' => $hasRecord,
                    'has_dir' => $hasDir,
                    'record' => $oldPlugin ? PluginManifestService::enrichPlugin($oldPlugin) : null,
                    'status' => $oldPlugin ? (int)$oldPlugin->status : null,
                    'version' => $currentVersion,
                ],
                'operation' => $operation,
                'requires_overwrite' => $hasRecord || $hasDir,
                'can_overwrite' => $upgrade['can_upgrade'] && $operation !== 'downgrade',
                'can_install' => empty($errors),
                'auto_disable' => [
                    'needed'             => $upgrade['need_cascade'],
                    'target_was_enabled' => $upgrade['target_was_enabled'],
                    'target_title'       => (string)($manifest['title'] ?: $pluginName),
                    'will_disable'       => $upgrade['will_disable'],
                ],
                'compatibility_info' => $compatibility,
                'dependency_info' => $dependencies,
                'migration_info' => $migrationInfo,
                'warnings' => $warnings,
                'errors' => array_values(array_unique($errors)),
            ];
        } finally {
            if (is_dir($workDir)) {
                Directory::removeDirWithin($workDir, $packageRoot);
            }
        }
    }

    /** 安装插件并执行生命周期钩子。 */
    public static function install(string $name): Plugin
    {
        return self::changeLifecycle($name, 'beforeInstall', 'afterInstall', function (Plugin $plugin) {
            DatabaseService::executeInstallSql($plugin);
            $plugin->status = 0;
            $plugin->installed_at = date('Y-m-d H:i:s');
            $plugin->error_message = '';
            $plugin->save();
            // 安装后生成/同步插件"管理入口"顶级菜单（syncEntry 为该保留 code 的唯一写入者，幂等且自愈重复行）
            self::syncEntry($plugin);
        }, function (Plugin $plugin) {
            $status = (int)$plugin->status;
            if ($status === 0 || $status === 1) {
                throw new ApiException('插件已安装，无需重复安装');
            }
            if ($status !== 2 && $status !== -1) {
                throw new ApiException('插件状态异常，不允许安装');
            }
            PluginManifestService::checkRequirements(PluginManifestService::getManifest($plugin));
        });
    }

    /** 备份插件文件和声明的数据表。 */
    public static function backup(string $name): Backup
    {
        $plugin = self::getPlugin($name);
        return BackupService::backupPlugin($plugin, PluginManifestService::getManifest($plugin), self::getBackupTables($plugin));
    }

    /** 恢复插件备份包中的文件和 restore.sql。 */
    public static function restorePackage(string $zipPath): array
    {
        [$workDir, $packageRoot, $extractDir] = self::validateAndPreparePackage($zipPath, 'restore_', '备份包不存在');

        $backupDir = null;
        $oldSnapshot = null;
        $targetDir = '';
        $pluginName = '';
        $targetReplaced = false;
        $completed = false;

        try {
            FileService::extractZipSafely($zipPath, $extractDir);
            $sourceDir = FileService::findPackageSourceDir($extractDir);
            $restoreSql = $sourceDir . '/restore.sql';
            if (!is_file($restoreSql)) {
                throw new ApiException('备份包插件目录中未找到 restore.sql');
            }

            $manifest = PluginManifestService::completeManifest(PluginManifestService::readManifest($sourceDir . '/' . FileService::MANIFEST_FILE));
            $pluginName = $manifest['name'];
            $targetDir = FileService::pluginRoot() . '/' . $pluginName;
            $oldPlugin = Plugin::where('name', $pluginName)->find();
            $oldSnapshot = $oldPlugin ? $oldPlugin->toArray() : null;
            if ($oldPlugin && (int)$oldPlugin->status === 1) {
                throw new ApiException('插件正在启用中，请先禁用后再恢复');
            }

            $restoreSqlCopy = $workDir . '/restore.sql';
            if (!copy($restoreSql, $restoreSqlCopy)) {
                throw new ApiException('备份恢复 SQL 准备失败');
            }
            @unlink($restoreSql);

            Directory::ensureDir(dirname($targetDir));
            if (is_dir($targetDir)) {
                $backupDir = FileService::backupPluginDir($targetDir, $pluginName);
            }
            Directory::moveDir($sourceDir, $targetDir);
            $targetReplaced = true;

            $manifest['path'] = 'plugin/' . $pluginName;
            $plugin = PluginManifestService::syncPlugin($manifest, $oldPlugin ?: null);
            $plugin->status = 0;
            $plugin->enabled_at = null;
            $plugin->error_message = '';
            if (!$plugin->installed_at) {
                $plugin->installed_at = date('Y-m-d H:i:s');
            }
            $plugin->save();

            DatabaseService::deletePluginRestoreData($pluginName);
            DatabaseService::executeSqlFile($restoreSqlCopy);
            self::clearRuntimeCaches();
            $completed = true;

            return [
                'package' => basename($zipPath),
                'hash' => hash_file('sha256', $zipPath) ?: '',
                'plugin' => $pluginName,
                'restored_files' => true,
                'restore_sql' => 'restore.sql',
            ];
        } catch (\Throwable $e) {
            if ($targetReplaced && $backupDir) {
                FileService::restorePluginBackup($backupDir, $targetDir);
            } elseif ($targetReplaced && $targetDir !== '' && is_dir($targetDir)) {
                Directory::removeDirWithin($targetDir, FileService::pluginRoot());
            }
            if ($pluginName !== '') {
                self::restorePluginSnapshot($pluginName, $oldSnapshot);
            }
            throw $e;
        } finally {
            if ($completed && $backupDir && is_dir($backupDir)) {
                Directory::removeDirWithin($backupDir, FileService::pluginBackupRoot());
            }
            if (is_dir($workDir)) {
                Directory::removeDirWithin($workDir, $packageRoot);
            }
        }
    }

    /** 卸载插件，自动备份后彻底删除插件文件和数据库信息。 */
    public static function uninstall(string $name): bool
    {
        $plugin = self::getPlugin($name);
        $status = (int)$plugin->status;

        if ($status === 1) {
            throw new ApiException('插件正在启用中，请先禁用后再卸载');
        }
        if (!in_array($status, [0, 2], true)) {
            throw new ApiException('插件未安装或已卸载，不允许卸载');
        }
        if ($status === 0) {
            self::assertNoReverseDependencies($name, '卸载', false);
        }

        // 卸载前自动备份：此步在删除流程之前，任何故障（含裸 \Throwable）都转成可读 ApiException，
        // 避免 APP_DEBUG=false 时被框架兜底成无信息量的 "Server internal error"
        try {
            BackupService::backupPlugin($plugin, PluginManifestService::getManifest($plugin), self::getUninstallBackupTables($plugin));
        } catch (\Throwable $e) {
            throw new ApiException('卸载前备份失败：' . $e->getMessage());
        }

        try {
            // shutdown：文件还在时让插件做自有清理（外部连接、文件锁等）
            self::callHook($plugin, 'shutdown');

            Db::transaction(function () use ($name, $status) {
                $plugin = self::getPlugin($name);
                if ($status === 0) {
                    self::callHook($plugin, 'beforeUninstall');
                    self::callHook($plugin, 'destroy');
                }
                DatabaseService::deletePluginDatabaseInfo((string)$plugin->name, (int)$plugin->id);
            });
            FileService::removePluginFiles($name);
            // 卸载后：各 Registry 按 owner 对称清理（跨 worker 由下次重启自然收敛兜底）
            self::cleanupPluginRegistrations($name);
            self::clearRuntimeCaches();
        } catch (\Throwable $e) {
            throw new ApiException($e->getMessage());
        }
        return true;
    }


    /** 标记插件异常状态。 */
    protected static function markPluginError(string $name, \Throwable $e): void
    {
        $plugin = Plugin::where('name', $name)->find();
        if ($plugin) {
            $plugin->status = -1;
            $plugin->error_message = $e->getMessage();
            $plugin->save();
        }
    }

    /** 启用插件。 */
    public static function enable(string $name): Plugin
    {
        return self::changeLifecycle($name, 'beforeEnable', 'afterEnable', function (Plugin $plugin) {
            $plugin->status = 1;
            $plugin->enabled_at = date('Y-m-d H:i:s');
            $plugin->error_message = '';
            $plugin->save();
            // 启用时补齐/修正"管理入口"菜单（幂等，禁用后重启用可重建）
            self::syncEntry($plugin);
        }, function (Plugin $plugin) {
            $status = (int)$plugin->status;
            if ($status === 2) {
                throw new ApiException('请先安装插件');
            }
            if ($status === -1) {
                throw new ApiException('插件状态异常，请先重载或重新安装');
            }
            if ($status === 1) {
                throw new ApiException('插件已启用');
            }
            if ($status !== 0) {
                throw new ApiException('插件状态不允许启用');
            }
            PluginManifestService::checkRequirements(PluginManifestService::getManifest($plugin), true);
            // 付费应用启用前校验 License（免费应用自动跳过）
            StoreService::assertLicenseForEnable($plugin);
        });
    }

    /** 禁用插件。 */
    public static function disable(string $name): Plugin
    {
        self::assertNoReverseDependencies($name, '禁用', true);
        $plugin = self::changeLifecycle($name, 'beforeDisable', 'afterDisable', function (Plugin $plugin) use ($name) {
            $plugin->status = 0;
            $plugin->enabled_at = null;
            $plugin->error_message = '';
            $plugin->save();
            // 停用"管理入口"菜单：基座侧栏收起入口，用户进不去（重新启用由 enable→syncEntry 复位）
            self::disableEntryMenu($name);
        }, function (Plugin $plugin) {
            if ((int)$plugin->status !== 1) {
                throw new ApiException('插件未启用，不允许禁用');
            }
        });
        // 禁用成功后：shutdown 钩子（让插件做自有清理）+ 各 Registry 按 owner 对称清理
        self::callHook($plugin, 'shutdown');
        self::cleanupPluginRegistrations($name);
        return $plugin;
    }

    /** 重新读取插件配置并刷新关联数据。 */
    public static function reload(string $name): Plugin
    {
        $plugin = self::getPlugin($name);
        $manifestPath = base_path() . '/' . trim($plugin->path, '/') . '/' . FileService::MANIFEST_FILE;
        if (!is_file($manifestPath)) {
            throw new ApiException('插件配置文件不存在');
        }

        $manifest = PluginManifestService::completeManifest(PluginManifestService::readManifest($manifestPath));
        $manifest['path'] = $plugin->path;
        if ((int)$plugin->status === 1) {
            PluginManifestService::checkRequirements($manifest, true);
        }
        $plugin = PluginManifestService::syncPlugin($manifest, $plugin);
        // 重新读取配置后同步"管理入口"（manage_entry/title 可能变化）
        self::syncEntry($plugin);
        self::callHook($plugin, 'init');
        self::clearRuntimeCaches();
        return $plugin;
    }

    /** 解析安装包操作类型。 */
    protected static function resolvePackageOperation(?Plugin $oldPlugin, array $manifest): string
    {
        $installed = $oldPlugin && !$oldPlugin->isEmpty() && in_array((int)$oldPlugin->status, [0, 1], true);
        if (!$installed) {
            return 'install';
        }

        $currentVersion = PluginManifestService::normalizeVersion((string)$oldPlugin->version);
        $targetVersion = PluginManifestService::normalizeVersion((string)$manifest['version']);
        $compare = version_compare($targetVersion, $currentVersion);
        if ($compare < 0) {
            throw new ApiException('不支持降级安装，如需降级请先卸载当前插件后重新安装');
        }
        return $compare === 0 ? 'overwrite' : 'update';
    }

    /** 升级已安装插件。 */
    protected static function upgradeInstalledPlugin(Plugin $plugin, array $manifest, string $currentVersion, string $targetVersion, string $pluginRoot): Plugin
    {
        $plugin = self::getPlugin($plugin->name);
        try {
            self::callHook($plugin, 'beforeUpgrade');
            BackupService::executeUpgradeMigrations($plugin, $currentVersion, $targetVersion, $pluginRoot);
            $result = Db::transaction(function () use ($plugin, $manifest) {
                $plugin = self::getPlugin($plugin->name);
                $plugin = PluginManifestService::syncPlugin($manifest, $plugin);
                $plugin->status = 0;
                $plugin->error_message = '';
                $plugin->save();
                // 升级后按最新 title/entry 同步"管理入口"菜单
                self::syncEntry($plugin);
                self::callHook($plugin, 'afterUpgrade');
                return self::getPlugin($plugin->name);
            });
            // 事务提交后再清缓存，避免提交前并发请求用旧数据回填缓存
            self::clearRuntimeCaches();
            return $result;
        } catch (\Throwable $e) {
            self::markPluginError((string)$plugin->name, $e);
            self::clearRuntimeCaches();
            throw $e;
        }
    }

    /** 同版本覆盖插件。 */
    protected static function overwriteSameVersionPlugin(Plugin $plugin, array $manifest): Plugin
    {
        $result = Db::transaction(function () use ($plugin, $manifest) {
            $plugin = PluginManifestService::syncPlugin($manifest, $plugin);
            $plugin->status = 0;
            $plugin->error_message = '';
            $plugin->save();
            return self::getPlugin($plugin->name);
        });
        // 事务提交后再清缓存，避免提交前并发请求用旧数据回填缓存
        self::clearRuntimeCaches();
        return $result;
    }

    /**
     * 清理插件相关的运行时缓存。
     * 状态变更（启用/禁用/卸载/锁定）与系统还原后都要调用，清单见 config/cache_manage.php 的 groups.runtime。
     */
    public static function clearRuntimeCaches(): void
    {
        CacheManager::clearGroup('runtime');
    }

    /** 执行插件生命周期变更。 */
    protected static function changeLifecycle(string $name, string $beforeHook, string $afterHook, callable $callback, ?callable $validator = null): Plugin
    {
        $plugin = self::getPlugin($name);
        if ($validator) {
            $validator($plugin);
        }

        try {
            $result = Db::transaction(function () use ($name, $beforeHook, $afterHook, $callback) {
                $plugin = self::getPlugin($name);
                self::callHook($plugin, $beforeHook);
                $callback($plugin);
                self::callHook($plugin, $afterHook);
                return self::getPlugin($plugin->name);
            });
            // 事务提交成功后统一清理缓存：避免在事务内清理时，提交前被并发请求用旧数据回填
            self::clearRuntimeCaches();
            return $result;
        } catch (\Throwable $e) {
            self::markPluginError($name, $e);
            throw $e;
        }
    }

    /** 按插件标识获取插件。 */
    public static function getPlugin(string $name): Plugin
    {
        $plugin = Plugin::where('name', $name)->findOrEmpty();
        if ($plugin->isEmpty()) {
            throw new ApiException('插件不存在');
        }
        return $plugin;
    }

    /**
     * 清理插件在全部 Registry 的静态注册（禁用/卸载后由基座调用）。
     *
     * 对称清理入口：所有注册表（Event/Service/Adapter/Notify/Identity/Middleware/Task）
     * 按 owner=插件名 统一清理。跨 worker 一致性由"下次进程重启自然收敛"兜底
     * （跨进程即时生效需依赖文件监控重载或 Redis 订阅）。
     */
    public static function cleanupPluginRegistrations(string $pluginName): void
    {
        \app\basic\EventRegistry::forgetByOwner($pluginName);
        \app\basic\ServiceRegistry::forgetByOwner($pluginName);
        \app\basic\AdapterRegistry::forgetByOwner($pluginName);
        \app\service\NotifyService::forgetByOwner($pluginName);
        \app\service\IdentityService::forgetByOwner($pluginName);
        \app\basic\MiddlewareRegistry::forgetByOwner($pluginName);
        \app\basic\TaskRegistry::forgetByOwner($pluginName);
        \app\basic\ChannelAuthRegistry::forgetByOwner($pluginName);
        QueueService::forgetByOwner($pluginName);
        CacheManager::forgetByOwner($pluginName);
    }

    /** 获取依赖当前插件的插件列表。 */
    protected static function getReverseDependencies(string $name, bool $enabledOnly = false): array
    {
        $query = Plugin::where('name', '<>', $name);
        if ($enabledOnly) {
            $query->where('status', 1);
        } else {
            $query->whereIn('status', [0, 1]);
        }

        $dependents = [];
        foreach ($query->select() as $plugin) {
            $manifest = \normalizeArray($plugin->manifest) ?: [];
            foreach (\normalizeArray($manifest['dependencies'] ?? []) as $dependency) {
                $dependency = PluginManifestService::normalizeDependency($dependency);
                if ($dependency['name'] === $name) {
                    $dependents[] = $plugin->toArray();
                    break;
                }
            }
        }
        return $dependents;
    }

    /** 确保当前插件没有被其它插件依赖。 */
    protected static function assertNoReverseDependencies(string $name, string $action, bool $enabledOnly = false): void
    {
        $dependents = self::getReverseDependencies($name, $enabledOnly);
        if (!$dependents) {
            return;
        }
        $titles = array_map(fn($plugin) => $plugin['title'] ?: $plugin['name'], $dependents);
        throw new ApiException('插件被以下插件依赖，不能' . $action . '：' . implode('、', $titles));
    }

    /**
     * 级联升级：以「启用中的依赖方」为边，求目标插件的传递依赖闭包（不含目标自身）。
     *
     * 返回顺序为「停用顺序」（后序：最深的依赖方排在最前），重启顺序为其逆序。
     * 停用任一节点时其上必无启用中的依赖方，disable() 护栏天然通过；
     * 逆序重启时依赖已就绪，enable() 的 checkRequirements 天然满足。
     * 命中回边即抛异常（依赖成环），交由上层退人工处理。
     *
     * @return array<int, array{name:string,title:string,status:int,enabled_at:mixed}>
     */
    public static function getUpgradeDependents(string $rootName): array
    {
        $order = [];
        // 访问状态：0=未访问 1=在当前 DFS 栈上 2=已完成
        $state = [];
        $visit = function (string $name, ?array $meta) use (&$visit, &$order, &$state, $rootName): void {
            $status = $state[$name] ?? 0;
            if ($status === 1) {
                throw new ApiException('依赖存在环：' . (($meta['title'] ?? null) ?: $name) . '，级联升级请人工处理');
            }
            if ($status === 2) {
                return;
            }
            $state[$name] = 1;
            foreach (self::getReverseDependencies($name, true) as $dependent) {
                $visit((string)$dependent['name'], $dependent);
            }
            $state[$name] = 2;
            if ($name !== $rootName && $meta !== null) {
                $order[] = [
                    'name'       => (string)$meta['name'],
                    'title'      => (string)($meta['title'] ?? $meta['name']),
                    'status'     => (int)($meta['status'] ?? 1),
                    'enabled_at' => $meta['enabled_at'] ?? null,
                ];
            }
        };
        $visit($rootName, null);
        return $order;
    }

    /**
     * 级联升级：非破坏性复制目标插件目录做快照（不影响 installPackage 自身的覆盖前备份），
     * 返回快照目录路径；插件目录不存在返回 null。
     */
    public static function snapshotPluginDir(string $name): ?string
    {
        $targetDir = self::pluginDirOf($name);
        if ($targetDir === null || !is_dir($targetDir)) {
            return null;
        }
        $snapRoot = runtime_path() . '/plugin_cascade_snapshots';
        Directory::ensureDir($snapRoot);
        $snapDir = $snapRoot . '/' . $name . '_' . date('YmdHis') . '_' . uniqid();
        Directory::copyDir($targetDir, $snapDir);
        return $snapDir;
    }

    /** 级联升级回滚：用快照覆盖当前插件目录（先清空目标再回填）。 */
    public static function restorePluginDirFromSnapshot(string $name, string $snapDir): void
    {
        $targetDir = self::pluginDirOf($name);
        if ($targetDir === null || !is_dir($snapDir)) {
            return;
        }
        if (is_dir($targetDir)) {
            Directory::removeDirWithin($targetDir, FileService::pluginRoot());
        }
        Directory::copyDir($snapDir, $targetDir);
    }

    /** 级联升级：清理快照目录。 */
    public static function removeSnapshotDir(?string $snapDir): void
    {
        if ($snapDir !== null && $snapDir !== '' && is_dir($snapDir)) {
            Directory::removeDir($snapDir);
        }
    }

    // ------------------------------------------------------------------
    // 升级编排（应用市场级联升级与基座本地安装包升级共用同一套）
    // ------------------------------------------------------------------

    /**
     * 升级预检：目标启用态 + 依赖闭包 → 本次将临时停用哪些插件、能否自动编排。
     * 启用中不再是阻断项（会自动停用并在装完后恢复）；唯一阻断项是依赖成环——
     * 停用/重启顺序排不出来，编排不了，只能退人工处理。
     *
     * @param Plugin|null $plugin 已取到的目标记录（省一次查询），null 时按 name 自查
     * @return array{need_cascade:bool,target_was_enabled:bool,will_disable:array<int,array{name:string,title:string}>,can_upgrade:bool,block_reason:string}
     */
    public static function preflightUpgrade(string $name, ?Plugin $plugin = null): array
    {
        $plugin ??= Plugin::where('name', $name)->find();
        $targetWasEnabled = $plugin !== null && (int)$plugin->status === 1;

        $blockReason = '';
        try {
            $dependents = self::getUpgradeDependents($name);
        } catch (ApiException $e) {
            $blockReason = $e->getMessage();
            $dependents = [];
        }

        return [
            'need_cascade'       => $targetWasEnabled || $dependents !== [],
            'target_was_enabled' => $targetWasEnabled,
            'will_disable'       => array_map(
                static fn($d) => ['name' => (string)$d['name'], 'title' => (string)$d['title']],
                $dependents
            ),
            'can_upgrade'        => $blockReason === '',
            'block_reason'       => $blockReason,
        ];
    }

    /**
     * 升级编排：串行锁 → 记录前置状态 → 文件/业务表快照 → 叶子先停 → $install() →
     * 逆序恢复启用（仅恢复原本启用的）→ 清缓存 + 热重载；任一步抛出即全量回滚。
     *
     * @param callable      $install    执行真正的安装，返回 ['plugin'=>?Plugin, 'zipPath'=>string]；
     *                                  临时包由调用方清理（本地包路径的 zip 属文件库，不可在此删）
     * @param array         $willDisable preflightUpgrade()['will_disable']，即停用顺序
     * @param callable|null $guard      锁内前置校验：在任何状态变更前抛出即中止，此时未动过文件与库，无需回滚
     * @return array{success:bool,message:string,data:array,install:array}
     */
    public static function runUpgrade(string $name, callable $install, array $willDisable, ?callable $guard = null): array
    {
        $lockToken = Security::randomHex(8);
        if (!Redis::set(self::UPGRADE_LOCK_KEY, $lockToken, 'EX', self::UPGRADE_LOCK_TTL, 'NX')) {
            return ['success' => false, 'message' => '有其他插件升级正在进行，请稍后再试', 'data' => ['ok' => false], 'install' => []];
        }

        // 临界区状态（提前声明，确保任一步抛出时回滚都能拿到已产生的信息）
        $mayHaveMutated = false;
        $snapDir = null;
        $dbSnapshot = [];
        $dbBackedUp = false;
        $preStates = [];
        $restarted = [];
        $pluginSnapshot = null;      // 还原 plugin_app 记录用（version/manifest）
        $migrationBaseline = [];     // 迁移台账基线（回滚时删除本次新增）
        $installResult = [];

        try {
            // 0. 锁内前置校验（未产生任何变更，失败直接中止，不走回滚）
            if ($guard !== null) {
                $guard();
            }
            $mayHaveMutated = true;

            // 1. 记录升级前状态：启用态 + plugin_app 记录 + 迁移台账基线
            $target = Plugin::where('name', $name)->find();
            if (!$target) {
                throw new ApiException('应用未安装');
            }
            $preStates[$name] = ['status' => (int)$target->status, 'enabled_at' => $target->enabled_at];
            $pluginSnapshot = ['version' => (string)$target->version, 'manifest' => $target->manifest];
            foreach ($willDisable as $d) {
                $p = Plugin::where('name', $d['name'])->find();
                if ($p) {
                    $preStates[$d['name']] = ['status' => (int)$p->status, 'enabled_at' => $p->enabled_at];
                }
            }
            $migrationBaseline = BackupService::getAppliedMigrationNames($name);

            // 2. 预备份：文件快照 + 业务表数据快照（DB 快照失败则回滚降级为文件+启用态）
            try {
                $snapDir = self::snapshotPluginDir($name);
                $tables = DatabaseService::pluginTableNames($name);
                $dbSnapshot = $tables ? DatabaseService::pluginTableSnapshotsByNames($tables) : [];
                $dbBackedUp = true;
            } catch (\Throwable $e) {
                $dbBackedUp = false;
                Log::error('[runUpgrade] 升级前快照失败 app=' . $name . ' err=' . $e->getMessage());
            }

            // 3. 停用阶段：按叶子先停顺序逐个停用依赖方，再停目标
            foreach ($willDisable as $d) {
                $p = Plugin::where('name', $d['name'])->find();
                if ($p && (int)$p->status === 1) {
                    self::disable($d['name']);
                }
            }
            $target = Plugin::where('name', $name)->find();
            if ((int)$target->status === 1) {
                self::disable($name);
            }

            // 4. 安装阶段（此时目标已禁用，校验天然通过）
            $installResult = (array)$install();

            // 5. 重启阶段：先起目标再起依赖方（逆序），仅恢复原本启用的
            if ((int)($preStates[$name]['status'] ?? 0) === 1) {
                self::enable($name);
            }
            foreach (array_reverse($willDisable) as $d) {
                $nm = (string)$d['name'];
                if ((int)($preStates[$nm]['status'] ?? 0) === 1) {
                    self::enable($nm);
                    $restarted[] = $nm;
                }
            }

            // 6. 收尾
            self::clearRuntimeCaches();
            $reload = PluginComposerService::gracefulReload();
            self::removeSnapshotDir($snapDir);
            $plugin = $installResult['plugin'] ?? null;

            // 文案与实际恢复范围一致：本就没有启用态要恢复时不提「相关插件」
            $recovered = (int)$preStates[$name]['status'] === 1 || $restarted !== [];
            $successMessage = !$recovered
                ? '升级成功'
                : ($reload['ok'] ? '升级成功，相关插件已自动恢复' : '升级成功，请重启服务使变更完全生效');

            return ['success' => true, 'message' => $successMessage, 'data' => [
                'ok'              => true,
                'reloaded'        => (bool)$reload['ok'],
                'need_reload'     => !$reload['ok'],
                'reload_message'  => (string)$reload['message'],
                'affected_enable' => $restarted,
                'plugin'          => $plugin ? PluginManifestService::enrichPlugin($plugin) : [],
            ], 'install' => $installResult];
        } catch (\Throwable $e) {
            // 前置校验阶段抛出：文件与库都没动过，如实报错即可，不需要（也不该）回滚
            if (!$mayHaveMutated) {
                Log::warning('[runUpgrade] 升级前置校验中止 app=' . $name . ' err=' . $e->getMessage());
                return ['success' => false, 'message' => $e->getMessage(), 'data' => ['ok' => false], 'install' => []];
            }
            $revert = self::revertUpgrade($name, $snapDir, $dbSnapshot, $dbBackedUp, $preStates, $willDisable, $pluginSnapshot, $migrationBaseline);
            Log::error('[runUpgrade] 升级失败已回滚 app=' . $name . ' err=' . $e->getMessage());
            return [
                'success' => false,
                'message' => '升级失败，已回滚至升级前状态：' . $e->getMessage(),
                'data'    => array_merge($revert, ['ok' => false, 'reverted' => true]),
                'install' => $installResult,
            ];
        } finally {
            // 锁统一在此释放（令牌比对，幂等）：无论快照/停用/安装/重启在哪一步抛出都能释放，杜绝锁泄漏
            try {
                if ((string)Redis::get(self::UPGRADE_LOCK_KEY) === $lockToken) {
                    Redis::del(self::UPGRADE_LOCK_KEY);
                }
            } catch (\Throwable $ignored) {
                // 释放失败交由 TTL 兜底
            }
        }
    }

    /**
     * 本地安装包入口：与市场升级一致——自动停用 → 覆盖安装 → 逆序恢复启用，失败全量回滚。
     * 无插件记录（首次安装）没有启用态要处理，直接走 installPackage。
     *
     * @return array{success:bool,message:string,data:array,plugin_name:string}
     */
    public static function installPackageAutoDisable(string $zipPath, bool $overwrite = false): array
    {
        $manifest = self::readPackageManifest($zipPath);
        $name = (string)($manifest['name'] ?? '');
        // 官方分发名的本地包同样以发布清单为准：市场路径已在 StoreService 校验过，这里补的是本地上传口，
        // 缺了它等于用一个同名的未发布包顶掉分发中心自身的实现。
        // 非官方名（自研/第三方包）不在清单内，不校验，否则本地开发的包就装不上。
        if (in_array($name, PluginManifestService::OFFICIAL_NAMES, true)) {
            ReleaseManifestService::check($zipPath, $name, (string)($manifest['version'] ?? ''), 0);
        }
        $plugin = Plugin::where('name', $name)->find();
        if (!$plugin) {
            // 首次安装：没有启用态要停用 / 恢复，直接装
            $installed = self::installPackage($zipPath, $overwrite);
            return [
                'success'     => true,
                'message'     => '安装插件包成功',
                'data'        => ['ok' => true],
                'plugin_name' => (string)$installed->name,
            ];
        }

        $upgrade = self::preflightUpgrade($name, $plugin);
        if (!$upgrade['can_upgrade']) {
            throw new ApiException($upgrade['block_reason']);
        }

        $result = self::runUpgrade(
            $name,
            static fn(): array => ['plugin' => self::installPackage($zipPath, $overwrite)],
            $upgrade['will_disable']
        );

        return [
            'success'     => (bool)$result['success'],
            'message'     => (string)$result['message'],
            'data'        => is_array($result['data'] ?? null) ? $result['data'] : [],
            'plugin_name' => $name,
        ];
    }

    /** 只读安装包 manifest（不改任何文件、不落库），供升级编排确定目标与依赖范围。 */
    private static function readPackageManifest(string $zipPath): array
    {
        [$workDir, $packageRoot, $extractDir] = self::validateAndPreparePackage($zipPath, 'meta_');
        try {
            FileService::extractZipSafely($zipPath, $extractDir);
            $sourceDir = FileService::findPackageSourceDir($extractDir);
            $manifest = PluginManifestService::completeManifest(PluginManifestService::readManifest($sourceDir . '/' . FileService::MANIFEST_FILE));
            $manifest['is_custom'] = 0;
            return $manifest;
        } finally {
            if (is_dir($workDir)) {
                Directory::removeDirWithin($workDir, $packageRoot);
            }
        }
    }

    /**
     * 升级失败回滚：逆序还原——目标文件快照、业务表数据、迁移台账、plugin_app 记录、启用态。
     * 每一步独立 try/catch，失败只记录不中断（best-effort）；返回回滚明细供前端如实提示。
     */
    private static function revertUpgrade(
        string $name,
        ?string $snapDir,
        array $dbSnapshot,
        bool $dbBackedUp,
        array $preStates,
        array $willDisable,
        ?array $pluginSnapshot,
        array $migrationBaseline
    ): array {
        $out = ['file_rolled_back' => false, 'db_rolled_back' => null, 'enable_failed' => []];
        if ($name === '') {
            return $out; // 锁内尚未解析出目标，未产生任何变更
        }

        // 1. 文件回滚
        try {
            if ($snapDir !== null && is_dir($snapDir)) {
                self::restorePluginDirFromSnapshot($name, $snapDir);
                self::removeSnapshotDir($snapDir);
                $out['file_rolled_back'] = true;
            }
        } catch (\Throwable $e) {
            Log::error('[revertUpgrade] 文件回滚失败 app=' . $name . ' err=' . $e->getMessage());
        }

        // 2. 业务表数据回滚（先 DROP 后按快照重建 + 回灌）
        if ($dbBackedUp) {
            try {
                self::restorePluginTables($name, $dbSnapshot);
                $out['db_rolled_back'] = true;
            } catch (\Throwable $e) {
                Log::error('[revertUpgrade] DB回滚失败 app=' . $name . ' err=' . $e->getMessage());
                $out['db_rolled_back'] = false;
                $out['db_note'] = '数据自动回滚失败，请按升级前备份人工恢复';
            }
            // 2b. 迁移台账：删除本次新增记录，保证重试能重新执行迁移 SQL
            try {
                BackupService::pruneMigrationsExcept($name, $migrationBaseline);
            } catch (\Throwable $e) {
                Log::error('[revertUpgrade] 迁移台账清理失败 app=' . $name . ' err=' . $e->getMessage());
            }
        } else {
            $out['db_rolled_back'] = false;
            $out['db_note'] = '升级前未能生成数据快照，本次仅回滚了文件与启用态，数据迁移需人工按备份恢复';
        }

        // 2c. 还原 plugin_app 记录（version/manifest），status 置 0 以便后续 enable 正常校验
        try {
            if ($pluginSnapshot !== null) {
                $row = Plugin::where('name', $name)->find();
                if ($row) {
                    $row->save([
                        'version'       => $pluginSnapshot['version'],
                        'manifest'      => $pluginSnapshot['manifest'],
                        'status'        => 0,
                        'enabled_at'    => null,
                        'error_message' => '',
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('[revertUpgrade] 还原插件记录失败 app=' . $name . ' err=' . $e->getMessage());
        }

        // 3. 启用态回滚：仅重新 enable「原本启用」的（本次从不主动启用原本禁用的，无需反向 disable）
        //    文件/数据回滚后目标已回到旧版本，按逆序先起目标再起依赖方
        self::clearRuntimeCaches();
        if ((int)($preStates[$name]['status'] ?? 0) === 1) {
            try {
                self::enable($name);
            } catch (\Throwable $e) {
                Log::error('[revertUpgrade] 重新启用目标失败 app=' . $name . ' err=' . $e->getMessage());
                $out['enable_failed'][] = $name;
            }
        }
        foreach (array_reverse($willDisable) as $d) {
            $nm = (string)$d['name'];
            if ((int)($preStates[$nm]['status'] ?? 0) === 1) {
                try {
                    self::enable($nm);
                } catch (\Throwable $e) {
                    Log::error('[revertUpgrade] 重新启用依赖方失败 nm=' . $nm . ' err=' . $e->getMessage());
                    $out['enable_failed'][] = $nm;
                }
            }
        }

        return $out;
    }

    /**
     * 按快照回滚插件自有业务表：DROP 现有同名表 → 按快照结构重建并回灌行。
     * 外键开关用 try/finally 收口（常驻 worker 复用连接，异常路径必须复位 FK=1）。
     * 仅限插件自有表；跨表/核心表变更不在自动回滚范围（由上层如实提示）。
     */
    private static function restorePluginTables(string $name, array $snapshot): void
    {
        if (!$snapshot) {
            return;
        }
        $root = runtime_path() . '/plugin_cascade_restore';
        Directory::ensureDir($root);
        $sqlFile = $root . '/' . $name . '_' . date('YmdHis') . '_' . uniqid() . '.sql';

        $lines = [];
        foreach ($snapshot as $t) {
            $lines[] = 'DROP TABLE IF EXISTS ' . DatabaseService::quoteIdentifier((string)$t['table']) . ';';
        }
        $lines[] = DatabaseService::pluginTablesToSql($snapshot, false);
        if (file_put_contents($sqlFile, implode("\n", $lines)) === false) {
            throw new ApiException('回滚 SQL 写入失败：' . $sqlFile);
        }
        try {
            Db::execute('SET FOREIGN_KEY_CHECKS=0');
            try {
                DatabaseService::executeSqlFile($sqlFile);
            } finally {
                Db::execute('SET FOREIGN_KEY_CHECKS=1');
            }
        } finally {
            @unlink($sqlFile);
        }
    }

    /** 依 path 字段解析插件在磁盘上的目录（找不到记录或 path 为空返回 null）。 */
    protected static function pluginDirOf(string $name): ?string
    {
        $plugin = Plugin::where('name', $name)->find();
        if (!$plugin) {
            return null;
        }
        $path = trim((string)$plugin->path, '/');
        return $path === '' ? null : base_path() . '/' . $path;
    }

    /** 获取卸载前需要备份的业务表。 */
    protected static function getUninstallBackupTables(Plugin $plugin): array
    {
        return array_values(array_unique(array_merge(
            self::getBackupTables($plugin),
            DatabaseService::pluginTableNames((string)$plugin->name)
        )));
    }

    /** 获取插件声明的备份业务表。 */
    public static function getBackupTables(Plugin $plugin): array
    {
        $manifest = PluginManifestService::getManifest($plugin);
        $hook = $manifest['hook'] ?? '';
        if (!$hook || !class_exists($hook) || !method_exists($hook, 'backupTables')) {
            return [];
        }

        $instance = new $hook();
        $method = new \ReflectionMethod($instance, 'backupTables');
        $tables = $method->getNumberOfParameters() === 0
            ? $instance->backupTables()
            : $instance->backupTables($plugin->toArray());
        if (!is_array($tables)) {
            throw new ApiException('插件备份表声明必须返回数组');
        }

        $name = (string)$plugin->name;
        $prefix = $name . '_';
        $normalized = [];
        foreach ($tables as $table) {
            $table = trim((string)$table);
            if ($table === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)
                || (!str_starts_with($table, $prefix) && $table !== $name)) {
                throw new ApiException('插件备份表声明不正确：' . $table);
            }
            if (!DatabaseService::tableExists($table)) {
                throw new ApiException('插件备份表不存在：' . $table);
            }
            $normalized[$table] = $table;
        }
        return array_values($normalized);
    }

    /** 调用插件生命周期钩子。 */
    protected static function callHook(Plugin $plugin, string $method): void
    {
        $manifest = PluginManifestService::getManifest($plugin);
        $hook = $manifest['hook'] ?? '';
        if (!$hook) {
            return;
        }
        if (!class_exists($hook)) {
            // manifest 配置了钩子类但类不存在，属于异常配置，记录便于排查（未实现可选钩子属正常情况，不记）
            Log::warning('插件钩子类不存在：' . $plugin->name . ' -> ' . $hook);
            return;
        }
        if (!method_exists($hook, $method)) {
            return;
        }

        $instance = new $hook();
        if ((new \ReflectionMethod($instance, $method))->getNumberOfParameters() === 0) {
            $instance->{$method}();
            return;
        }

        $instance->{$method}($plugin->toArray());
    }

    /**
     * 判定插件是否声明了可见入口（应用入口或管理入口任一非空）。
     *
     * 两者均为空即"纯插件"（只提供服务能力、无页面），基座不创建入口菜单；
     * manifest 显式提供 is_backstage 时以其为准（第三方插件的权威声明）。
     */
    public static function hasVisibleEntry(Plugin $plugin): bool
    {
        return self::resolveVisibleEntry($plugin) !== '';
    }

    /**
     * 解析插件在基座打开时使用的入口地址：优先管理入口，无管理入口回退应用入口，两者皆空返回空串。
     */
    public static function resolveVisibleEntry(Plugin $plugin): string
    {
        // 入口声明只存于 manifest JSON（plugin_app 表无 entry/manage_entry 独立列），
        // 以 getManifest（含 DB manifest 列）为准；个别记录 manifest 缺入口时再回退磁盘清单。
        $entry = PluginManifestService::visibleEntryFromManifest(PluginManifestService::getManifest($plugin));
        if ($entry !== '') {
            return $entry;
        }
        return PluginManifestService::visibleEntryFromManifest(PluginManifestService::fileManifest($plugin));
    }

    /** 插件"管理入口"菜单 code 的保留前缀。 */
    public const ENTRY_CODE_PREFIX = 'app:entry:';

    /** 插件"管理入口"菜单的保留 code。 */
    public static function entryCode(string $name): string
    {
        return self::ENTRY_CODE_PREFIX . $name;
    }

    /**
     * 插件入口菜单的基座路由地址（不含记录 id）。
     *
     * 宿主路由只以插件标识为参数：标识在实例内唯一且不随备份迁移变化，而记录 id 会因
     * 目标库自增序列不同而变化，故地址不得携带 id，以便菜单数据原样迁移。
     */
    public static function entryMenuPath(string $name): string
    {
        return '/plugin-app/' . $name;
    }

    /**
     * 停用插件"管理入口"菜单：只按保留 code 改 status，不建不删。
     *
     * 不复用 syncEntry——禁用往往正是插件本身出问题时的兜底操作（manifest 缺失、状态异常），
     * 那时 syncEntry 读 manifest 与自愈重建都可能抛错，反而把禁用操作一起带失败。
     */
    public static function disableEntryMenu(string $name): void
    {
        $affected = Menu::where('code', self::entryCode($name))->where('status', 1)->update(['status' => 0]);
        if ($affected > 0) {
            UserMenuCache::clear();
        }
    }

    /**
     * 幂等生成/同步插件"管理入口"顶级菜单（安全网）。
     *
     * 未声明任何入口（纯插件）时删除可能残留的入口菜单；否则按保留 code 查找，存在则回写
     * title/icon/path/app_name，缺失则插入一条指向既有宿主路由 /plugin-app/{name} 的顶级菜单
     * （type=2、pid=0、is_hide=0 即显示；本库 is_hide=1 表示隐藏）。
     * 菜单 status 跟随插件 status：禁用即停用入口菜单，基座侧栏不再给出入口、用户进不来；
     * 重新启用时由 enable→本方法复位为 1。
     * 注意禁用期间该菜单不在角色授权树里（授权树只列启用菜单），操作者提交不出它的 id，
     * 保存权限会连带撤销对这些菜单的授权——故 RoleController::expandMenuIds 专门保留这类既有授权。
     * 子菜单由插件自己的 install.sql 维护并按 app_name 归属，删除入口行不影响它们。
     * 卸载清理由现有物理删除流程（pluginMenuRows 按 app_name 命中）负责，此处不做全量删除。
     */
    public static function syncEntry(Plugin $plugin): void
    {
        $name = (string)$plugin->name;
        $code = self::entryCode($name);
        $entry = self::resolveVisibleEntry($plugin);

        if ($entry === '') {
            // 纯插件：不进入口、不建菜单，并清理历史遗留的入口行（可能多行，全部删除以自愈重复）
            $stale = Menu::where('code', $code)->select();
            if (count($stale) > 0) {
                foreach ($stale as $row) {
                    $row->delete();
                }
                UserMenuCache::clear();
            }
            return;
        }

        $manifest = PluginManifestService::getManifest($plugin);
        $title = (string)($manifest['title'] ?? $plugin->title ?? $name);
        if ($title === '') {
            $title = $name;
        }
        $icon = (string)($manifest['icon'] ?? $plugin->icon ?? '');
        $path = self::entryMenuPath($name);
        // 入口菜单的启用态跟随插件：启用=1，其余状态（未启用/未安装/异常）=0
        $menuStatus = (int)$plugin->status === 1 ? 1 : 0;

        // 保留 code app:entry:* 归本方法独占：命中多行时保留最早一条并回写字段、删除其余重复行。
        // （system_menu.code 无唯一索引，历史上 install.sql 与程序各插一行会造成重复菜单，此处自愈。）
        $menus = Menu::where('code', $code)->order('id', 'asc')->select();
        if (count($menus) > 0) {
            $primary = null;
            foreach ($menus as $menu) {
                if ($primary === null) {
                    $primary = $menu;
                    continue;
                }
                $menu->delete();
            }
            $primary->save([
                'name' => $title,
                'icon' => $icon,
                'path' => $path,
                'app_name' => $name,
                'pid' => 0,
                'type' => 2,
                'component' => 'plugin/app',
                'is_hide' => 0,
                'status' => $menuStatus,
            ]);
        } else {
            Menu::create([
                'pid' => 0,
                'name' => $title,
                'code' => $code,
                'app_name' => $name,
                'type' => 2,
                'path' => $path,
                'component' => 'plugin/app',
                'icon' => $icon,
                'sort' => 100,
                'is_hide' => 0,
                'is_cache' => 0,
                'is_fixed' => 0,
                'is_full_page' => 0,
                'is_iframe' => 1,
                'status' => $menuStatus,
            ]);
        }
        UserMenuCache::clear();
    }

    /**
     * 逐个调用"已启用"插件的 boot() 运行时装配钩子。
     *
     * 由基座在每个 worker 进程启动时(Webman\Bootstrap::start)调用，用于插件重新登记
     * 进程级静态状态（如 IdentityService 的 provider/extender）。单个插件 boot 失败
     * 不得影响其他插件与进程启动，故逐个 try/catch。
     */
    public static function bootPlugins(): void
    {
        try {
            $plugins = Plugin::where('status', 1)->select();
        } catch (\Throwable $e) {
            // 数据库尚未就绪等基础设施异常：记录并跳过，不阻断进程启动
            Log::error('[PluginBoot] 读取已启用插件失败：' . $e->getMessage());
            return;
        }

        foreach ($plugins as $plugin) {
            try {
                self::callHook($plugin, 'boot');
                self::loadPluginEvents((string) $plugin->name);
                self::loadPluginCacheDomains((string) $plugin->name);
            } catch (\Throwable $e) {
                Log::error(sprintf(
                    '[PluginBoot] 插件 %s boot() 执行失败：%s',
                    (string) $plugin->name,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * 聚合插件目录 config/event.php 的事件监听到 EventRegistry。
     *
     * 文件格式沿用基座 config/event.php：'事件名' => [[Class, method]|callable, ...]；
     * 文件不存在/格式非法静默跳过，owner 记插件名（供禁用/卸载对称清理）。
     * 在 boot() 之后调用：插件可在 boot() 内以代码注册更高优先级监听。
     */
    protected static function loadPluginEvents(string $name): void
    {
        if (!config('plugin_extend.event_enabled', true)) {
            return;
        }
        $file = base_path() . '/plugin/' . $name . '/config/event.php';
        if (!is_file($file)) {
            return;
        }

        $map = require $file;
        if (!is_array($map)) {
            Log::warning('[PluginBoot] 插件 ' . $name . ' 的 config/event.php 格式非法（应为数组），已跳过');
            return;
        }
        foreach ($map as $event => $listeners) {
            foreach ((array) $listeners as $listener) {
                EventRegistry::listen((string) $event, $listener, 0, $name);
            }
        }
    }

    /**
     * 聚合插件目录 config/cache_manage.php 的缓存域声明到 CacheManager 运行期登记表。
     *
     * 文件格式与基座 config/cache_manage.php 单域同构：'域编码' => [name/prefix/expire/tag/...]。
     * 文件不存在/格式非法静默跳过，owner 记插件名（供禁用/卸载对称清理）。
     * 只影响后台「缓存监控/一键清理」视图；插件运行时读写缓存不依赖本声明。
     */
    protected static function loadPluginCacheDomains(string $name): void
    {
        $file = base_path() . '/plugin/' . $name . '/config/cache_manage.php';
        if (!is_file($file)) {
            return;
        }

        $domains = require $file;
        if (!is_array($domains)) {
            Log::warning('[PluginBoot] 插件 ' . $name . ' 的 config/cache_manage.php 格式非法（应为数组），已跳过');
            return;
        }
        CacheManager::registerPluginDomains($name, $domains);
    }

}
