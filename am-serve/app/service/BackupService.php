<?php

namespace app\service;

use app\exception\ApiException;
use app\model\plugin\App as Plugin;
use app\model\plugin\Widget;
use app\model\system\Backup;
use app\model\system\Config;
use app\model\system\File;
use app\model\system\Migration;
use app\model\system\Menu;
use app\utils\Directory;
use support\Log;
use support\think\Db;
use ZipArchive;

class BackupService
{

    public static function pluginUninstallBackupWorkRoot(): string
    {
        return runtime_path() . '/plugin_uninstall_backup_work';
    }

    public static function backupPlugin(Plugin $plugin, ?array $manifest = null, array $businessTables = []): Backup
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ApiException('当前 PHP 环境未安装 ZipArchive 扩展');
        }

        $name = (string)$plugin->name;
        $version = PluginManifestService::normalizeVersion((string)$plugin->version);
        $timestamp = date('YmdHis');
        $fileName = $name . '_' . $version . '_' . $timestamp . '.zip';
        $zipRoot = runtime_path() . '/plugin_backup_zip';
        $workRoot = self::pluginUninstallBackupWorkRoot() . '/' . pathinfo($fileName, PATHINFO_FILENAME);
        $workPluginRoot = $workRoot . '/' . $name;
        $sourceDir = FileService::pluginRoot() . '/' . $name;
        $zipPath = $zipRoot . '/' . $fileName;
        $fileModel = null;
        $record = null;

        Directory::ensureDir($zipRoot);
        Directory::ensureDir($workRoot);

        try {
            if (is_dir($sourceDir)) {
                Directory::copyDir($sourceDir, $workPluginRoot);
            } else {
                Directory::ensureDir($workPluginRoot);
            }

            $backupData = self::collectPluginBackupData($plugin, $businessTables);
            FileService::writePluginFile($workPluginRoot . '/restore.sql', $backupData['sql']['restore']);
            FileService::compressDirectory($workPluginRoot, $zipPath);

            $fileModel = UploadService::saveLocalFile($zipPath, [
                'name' => $fileName,
                'pid' => 0,
                'is_lock' => 1,
                'extends' => [
                    'source' => 'plugin_backup',
                    'app_name' => $name,
                    'title' => (string)($plugin->title ?? ''),
                    'version' => $version,
                ],
            ]);

            $record = new Backup();
            $record->save([
                'app_name' => $name,
                'title' => (string)($plugin->title ?? ''),
                'version' => $version,
                'backup_type' => Backup::TYPE_PLUGIN,
                'file_id' => $fileModel->id,
                'file_count' => FileService::countFiles($workPluginRoot),
                'database_summary' => $backupData['summary'],
                'plugin_snapshot' => $plugin->toArray(),
                'manifest' => $manifest ?? PluginManifestService::pluginManifestSnapshot($plugin),
                'status' => 1,
                'error_message' => '',
            ]);
            self::linkFileToBackup($fileModel, $record);
            return $record;
        } catch (\Throwable $e) {
            self::rollbackFailedBackup($record, $fileModel);
            throw $e;
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
            if (is_dir($workRoot)) {
                Directory::removeDirWithin($workRoot, self::pluginUninstallBackupWorkRoot());
            }
        }
    }


    public static function collectPluginBackupData(Plugin $plugin, array $businessTables = []): array
    {
        $name = (string)$plugin->name;
        $configRows = DatabaseService::pluginConfigRows($name);
        $menuRows = DatabaseService::pluginMenuRows($name);
        $widgetRows = DatabaseService::pluginWidgetRows($name);
        $pluginTables = DatabaseService::pluginTableSnapshotsByNames($businessTables);
        $systemConfigSql = DatabaseService::pluginConfigRowsToMigrationSql($name, $configRows, true);
        $systemMenuSql = DatabaseService::pluginMenuRowsToMigrationSql($name, $menuRows);
        $pluginWidgetSql = DatabaseService::pluginWidgetRowsToMigrationSql($name, $widgetRows);
        $pluginTablesSql = DatabaseService::pluginTablesToSql($pluginTables, true);
        $restoreSql = self::buildRestoreSql($plugin, [$systemConfigSql, $systemMenuSql, $pluginWidgetSql, $pluginTablesSql]);

        $summary = [
            'plugin' => $name,
            'version' => (string)$plugin->version,
            'system_config_count' => count($configRows),
            'system_menu_count' => count($menuRows),
            'plugin_widget_count' => count($widgetRows),
            'plugin_table_count' => count($pluginTables),
            'plugin_tables' => array_map(
                fn($table) => ['table' => $table['table'], 'row_count' => count($table['rows'])],
                $pluginTables
            ),
        ];

        return [
            'system_config' => $configRows,
            'system_menu' => $menuRows,
            'plugin_widget' => $widgetRows,
            'plugin_tables' => $pluginTables,
            'summary' => $summary,
            'sql' => [
                'system_config' => $systemConfigSql,
                'system_menu' => $systemMenuSql,
                'plugin_widget' => $pluginWidgetSql,
                'plugin_tables' => $pluginTablesSql,
                'restore' => $restoreSql,
            ],
        ];
    }

    protected static function buildRestoreSql(Plugin $plugin, array $sections): string
    {
        $header = [
            '-- Generated by Atrman Plugin Backup.',
            '-- Plugin: ' . (string)$plugin->name,
            '-- Version: ' . (string)$plugin->version,
            '-- Generated at: ' . date('Y-m-d H:i:s'),
            '',
            'SET FOREIGN_KEY_CHECKS=0;',
        ];
        $footer = [
            'SET FOREIGN_KEY_CHECKS=1;',
        ];
        return implode("\n\n", array_filter([
            implode("\n", $header),
            ...array_filter($sections),
            implode("\n", $footer),
        ])) . "\n";
    }

    public static function migrationRoot(string $pluginRoot): string
    {
        return rtrim($pluginRoot, '/\\') . '/migrations';
    }

    public static function parseMigrationVersion(string $fileName): string
    {
        if (preg_match('/^v?(\d+(?:\.\d+){0,3})(?:[._-].*)?\.sql$/i', $fileName, $matches)) {
            return $matches[1];
        }
        return '';
    }

    public static function listMigrationFiles(string $pluginRoot): array
    {
        $root = self::migrationRoot($pluginRoot);
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (scandir($root) ?: [] as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with(strtolower($file), '.sql')) {
                continue;
            }
            $version = self::parseMigrationVersion($file);
            if ($version === '') {
                throw new ApiException('迁移文件名必须以版本号开头：' . $file);
            }
            $path = $root . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $files[] = [
                'version' => $version,
                'migration' => $file,
                'path' => $path,
                'checksum' => hash_file('sha256', $path) ?: '',
            ];
        }

        usort($files, function (array $a, array $b) {
            $compare = PluginManifestService::comparePluginVersion($a['version'], $b['version']);
            return $compare !== 0 ? $compare : strcmp($a['migration'], $b['migration']);
        });
        return $files;
    }

    public static function getAppliedMigrationNames(string $pluginName): array
    {
        $rows = Migration::where('app_name', $pluginName)
            ->where('migration_type', Migration::TYPE_PLUGIN)
            ->where('status', Migration::STATUS_SUCCESS)
            ->select()
            ->toArray();
        return array_fill_keys(array_map(fn($row) => (string)$row['migration'], $rows), true);
    }

    /**
     * 删除给定基线之外新增的插件迁移记录（级联升级回滚用）。
     *
     * 否则回滚后表结构已还原为旧版，但台账仍标记本次迁移为已执行，
     * 重试时 getPendingMigrations 会跳过这些迁移 SQL → 新版代码跑在旧表结构上。
     *
     * @param array $baselineKeys getAppliedMigrationNames() 返回的基线（key=迁移名）
     * @return string[] 被删除的迁移名列表
     */
    public static function pruneMigrationsExcept(string $pluginName, array $baselineKeys): array
    {
        $rows = Migration::where('app_name', $pluginName)
            ->where('migration_type', Migration::TYPE_PLUGIN)
            ->select()
            ->toArray();
        $removed = [];
        foreach ($rows as $row) {
            $name = (string)($row['migration'] ?? '');
            if ($name !== '' && !isset($baselineKeys[$name])) {
                Migration::where('id', (int)$row['id'])->delete();
                $removed[] = $name;
            }
        }
        return $removed;
    }

    public static function buildMigrationInfo(string $pluginName, string $currentVersion, string $targetVersion, string $pluginRoot): array
    {
        $files = self::listMigrationFiles($pluginRoot);
        $appliedNames = self::getAppliedMigrationNames($pluginName);
        $pending = [];
        $applied = [];
        $skipped = [];

        foreach ($files as $file) {
            $inRange = PluginManifestService::comparePluginVersion($file['version'], $currentVersion) > 0
                && PluginManifestService::comparePluginVersion($file['version'], $targetVersion) <= 0;
            if (!$inRange) {
                $skipped[] = [
                    'version' => $file['version'],
                    'migration' => $file['migration'],
                    'checksum' => $file['checksum'],
                    'status' => 'skipped',
                ];
                continue;
            }

            $row = [
                'version' => $file['version'],
                'migration' => $file['migration'],
                'checksum' => $file['checksum'],
            ];
            if (isset($appliedNames[$file['migration']])) {
                $applied[] = $row + ['status' => 'applied'];
            } else {
                $pending[] = $row + ['status' => 'pending'];
            }
        }

        return [
            'current_version' => $currentVersion,
            'target_version' => $targetVersion,
            'has_migrations' => !empty($files),
            'pending_count' => count($pending),
            'applied_count' => count($applied),
            'skipped_count' => count($skipped),
            'pending' => $pending,
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    public static function getPendingMigrations(string $pluginName, string $currentVersion, string $targetVersion, string $pluginRoot): array
    {
        $info = self::buildMigrationInfo($pluginName, $currentVersion, $targetVersion, $pluginRoot);
        $files = array_column(self::listMigrationFiles($pluginRoot), null, 'migration');
        return array_values(array_map(
            fn($item) => $item + ['path' => $files[$item['migration']]['path'] ?? ''],
            $info['pending']
        ));
    }

    public static function executeUpgradeMigrations(Plugin $plugin, string $currentVersion, string $targetVersion, string $pluginRoot): void
    {
        $migrations = self::getPendingMigrations((string)$plugin->name, $currentVersion, $targetVersion, $pluginRoot);
        if (!$migrations) {
            return;
        }
        $batch = (int)Migration::where('app_name', $plugin->name)
            ->where('migration_type', Migration::TYPE_PLUGIN)
            ->max('batch') + 1;
        foreach ($migrations as $migration) {
            try {
                DatabaseService::executeSqlFile($migration['path']);
                self::saveMigrationRecord($plugin, $migration, $currentVersion, $targetVersion, $batch, Migration::STATUS_SUCCESS);
            } catch (\Throwable $e) {
                self::saveMigrationRecord($plugin, $migration, $currentVersion, $targetVersion, $batch, Migration::STATUS_FAILED, $e->getMessage());
                throw $e;
            }
        }
    }

    protected static function saveMigrationRecord(Plugin $plugin, array $migration, string $currentVersion, string $targetVersion, int $batch, int $status, string $errorMessage = ''): void
    {
        $record = Migration::where('app_name', $plugin->name)
            ->where('migration_type', Migration::TYPE_PLUGIN)
            ->where('migration', $migration['migration'])
            ->find();
        if (!$record) {
            $record = new Migration();
        }
        $record->save([
            'app_name' => $plugin->name,
            'migration_type' => Migration::TYPE_PLUGIN,
            'version' => $migration['version'],
            'migration' => $migration['migration'],
            'checksum' => $migration['checksum'],
            'from_version' => $currentVersion,
            'to_version' => $targetVersion,
            'batch' => $batch,
            'status' => $status,
            'error_message' => $errorMessage,
            'executed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // ────────────────────────────────────────────────────
    // 系统备份与恢复
    // ────────────────────────────────────────────────────

    /** 系统备份工作目录。 */
    public static function systemBackupWorkRoot(): string
    {
        return runtime_path() . '/system_backup_work';
    }

    /** 读取应用根目录 manifest.json。 */
    public static function readAppManifest(): array
    {
        $path = base_path() . '/manifest.json';
        if (!is_file($path)) {
            throw new ApiException('应用清单文件不存在');
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new ApiException('应用清单解析失败');
        }
        return $data;
    }

    /** 读取备份包中的 backup_manifest.json。 */
    public static function readBackupManifest(string $extractDir): array
    {
        $path = $extractDir . '/backup_manifest.json';
        if (!is_file($path)) {
            throw new ApiException('备份清单文件不存在');
        }
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new ApiException('备份清单解析失败');
        }
        return $data;
    }

    /**
     * 备份与恢复都不搬运的环境键前缀：数据库/Redis 指向属于运行环境，不该跟包走。
     * 备份侧剔除它们，恢复侧同样必须跳过——否则一个带 DB_/REDIS_ 键的包就能把站点指向外部服务。
     */
    private const ENV_SKIP_PREFIXES = ['DB_', 'REDIS_', 'CACHE_'];

    /** 过滤 .env 文件，仅保留密钥/标识类配置，剔除数据库和 Redis 环境配置。 */
    public static function filterEnvForBackup(string $envPath): string
    {
        if (!is_file($envPath)) {
            return '';
        }
        $lines = file($envPath, FILE_IGNORE_NEW_LINES) ?: [];
        $skipPrefixes = self::ENV_SKIP_PREFIXES;
        $filtered = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $eqPos));
            $skip = false;
            foreach ($skipPrefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) {
                $filtered[] = $trimmed;
            }
        }
        return implode("\n", $filtered) . "\n";
    }

    /**
     * 合并备份中的 .env 密钥到当前系统（不动数据库/Redis 配置）。
     *
     * @return array 被跳过的环境键名，交由调用方写进恢复结果提示，避免「以为带回去了」
     */
    public static function mergeEnvForRestore(string $envBackupPath): array
    {
        $skipped = [];
        if (!is_file($envBackupPath)) {
            return $skipped;
        }
        $backupLines = file($envBackupPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $currentEnvPath = base_path() . '/.env';
        $currentLines = is_file($currentEnvPath) ? (file($currentEnvPath, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $currentMap = [];
        foreach ($currentLines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $eqPos = strpos($trimmed, '=');
            if ($eqPos !== false) {
                $currentMap[trim(substr($trimmed, 0, $eqPos))] = $trimmed;
            }
        }
        // 仅覆盖备份中的密钥类配置
        foreach ($backupLines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $eqPos));
            // 与备份侧同一份剔除口径：包里的 DB_/REDIS_/CACHE_ 键一律不合并进来
            foreach (self::ENV_SKIP_PREFIXES as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    $skipped[] = $key;
                    continue 2;
                }
            }
            $currentMap[$key] = $trimmed;
        }
        $content = implode("\n", array_values($currentMap)) . "\n";
        file_put_contents($currentEnvPath, $content);

        return $skipped;
    }

    /** 收集系统数据库摘要信息。 */
    public static function collectSystemDatabaseSummary(): array
    {
        $tables = Db::query('SHOW TABLE STATUS');
        $summary = [];
        foreach ($tables as $table) {
            $summary[] = [
                'table' => $table['Name'] ?? '',
                'engine' => $table['Engine'] ?? '',
                'rows' => (int)($table['Rows'] ?? 0),
                'size' => (int)(($table['Data_length'] ?? 0) + ($table['Index_length'] ?? 0)),
            ];
        }
        return $summary;
    }

    /** 使用 mysqldump 导出全量数据库。 */
    public static function exportSystemDatabase(string $outputPath): void
    {
        $host = env('DB_HOSTNAME', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $database = env('DB_DATABASE', '');
        $charset = env('DB_CHARSET', 'utf8mb4');

        if ($database === '') {
            throw new ApiException('数据库配置不存在');
        }

        // 账号密码通过临时 defaults-file 传递，避免命令行参数泄露密码（ps/进程列表可见）
        $defaultsFile = self::createMysqlDefaultsFile();
        try {
            $cmd = sprintf(
                'mysqldump --defaults-file=%s --host=%s --port=%s --single-transaction --routines --triggers --default-character-set=%s %s > %s',
                escapeshellarg($defaultsFile),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($charset),
                escapeshellarg($database),
                escapeshellarg($outputPath)
            );

            $returnCode = 0;
            $output = [];
            exec($cmd . ' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                throw new ApiException('数据库导出失败：' . implode("\n", $output));
            }
            if (!is_file($outputPath) || filesize($outputPath) === 0) {
                throw new ApiException('数据库导出文件为空');
            }
        } finally {
            @unlink($defaultsFile);
        }
    }

    /** 导入数据库 SQL 文件。 */
    public static function importSystemDatabase(string $sqlPath): void
    {
        if (!is_file($sqlPath)) {
            throw new ApiException('数据库导入文件不存在');
        }

        $host = env('DB_HOSTNAME', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $database = env('DB_DATABASE', '');
        $charset = env('DB_CHARSET', 'utf8mb4');

        // 与 exportSystemDatabase 一致：账号密码走临时 defaults-file
        $defaultsFile = self::createMysqlDefaultsFile();
        try {
            $cmd = sprintf(
                'mysql --defaults-file=%s --host=%s --port=%s --default-character-set=%s %s < %s',
                escapeshellarg($defaultsFile),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($charset),
                escapeshellarg($database),
                escapeshellarg($sqlPath)
            );

            $returnCode = 0;
            $output = [];
            exec($cmd . ' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                throw new ApiException('数据库导入失败：' . implode("\n", $output));
            }
        } finally {
            @unlink($defaultsFile);
        }
    }

    /** 生成包含 MySQL 账号密码的临时配置文件（[client] 组，权限 0600），调用方负责删除 */
    protected static function createMysqlDefaultsFile(): string
    {
        $username = (string)env('DB_USERNAME', '');
        $password = (string)env('DB_PASSWORD', '');
        $file = (string)tempnam(sys_get_temp_dir(), 'ammycnf');
        // cnf 格式不支持多行值，换行直接剔除；双引号与反斜杠做转义
        $escape = fn(string $v): string => str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $v);
        $content = "[client]\nuser=\"" . $escape($username) . "\"\npassword=\"" . $escape($password) . "\"\n";
        file_put_contents($file, $content);
        @chmod($file, 0600);
        return $file;
    }

    /** 创建系统全量备份。 */
    public static function backupSystem(): Backup
    {
        if (!class_exists(ZipArchive::class)) {
            throw new ApiException('当前 PHP 环境未安装 ZipArchive 扩展');
        }

        $appManifest = self::readAppManifest();
        $version = (string)($appManifest['version'] ?? '1.0.0');
        $timestamp = date('YmdHis');
        $random = substr(bin2hex(random_bytes(3)), 0, 6);
        $fileName = 'system_backup_' . $timestamp . '_' . $random . '.zip';
        $workRoot = self::systemBackupWorkRoot() . '/' . $timestamp . '_' . $random;
        $tempZipPath = runtime_path() . '/system_backup_zip_' . $timestamp . '_' . $random . '.zip';
        $record = null;
        $fileModel = null;

        Directory::ensureDir($workRoot);

        try {
            // 1. 导出数据库
            self::exportSystemDatabase($workRoot . '/database.sql');

            // 2. 复制目录（public/storage 排除 backup 子目录，避免备份包递归增大）
            $dirsToBackup = ['plugin', 'config', 'vendor', 'public'];
            foreach ($dirsToBackup as $dir) {
                $sourceDir = base_path() . '/' . $dir;
                if (is_dir($sourceDir)) {
                    $exclude = ($dir === 'public') ? ['backup'] : [];
                    Directory::copyDir($sourceDir, $workRoot . '/' . $dir, $exclude);
                }
            }

            // 3. 过滤 .env
            $envBackup = self::filterEnvForBackup(base_path() . '/.env');
            if (@file_put_contents($workRoot . '/env.backup', $envBackup) === false) {
                throw new ApiException('.env 文件备份失败');
            }

            // 4. 复制 manifest.json
            if (is_file(base_path() . '/manifest.json')) {
                if (!@copy(base_path() . '/manifest.json', $workRoot . '/manifest.json')) {
                    throw new ApiException('manifest.json 复制失败');
                }
            }

            // 5. 生成 backup_manifest.json
            $pluginList = [];
            $pluginRoot = FileService::pluginRoot();
            if (is_dir($pluginRoot)) {
                foreach (scandir($pluginRoot) ?: [] as $item) {
                    if ($item !== '.' && $item !== '..' && is_dir($pluginRoot . '/' . $item)) {
                        $pluginList[] = $item;
                    }
                }
            }

            $backupManifest = [
                'app_name' => (string)($appManifest['name'] ?? 'atrman'),
                'app_title' => (string)($appManifest['title'] ?? ''),
                'app_version' => $version,
                'app_release' => (string)($appManifest['release'] ?? ''),
                'php_version' => PHP_VERSION,
                'mysql_version' => self::getMysqlVersion(),
                'backup_time' => date('Y-m-d H:i:s'),
                'backup_timestamp' => time(),
                'database_name' => (string)env('DB_DATABASE', ''),
                'database_charset' => (string)env('DB_CHARSET', 'utf8mb4'),
                'table_prefix' => (string)($appManifest['table_prefix'] ?? 'am_'),
                'plugin_list' => $pluginList,
                'included_dirs' => $dirsToBackup,
                'backup_type' => Backup::TYPE_SYSTEM,
            ];
            @file_put_contents(
                $workRoot . '/backup_manifest.json',
                json_encode($backupManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            // 6. 打包到临时 zip
            FileService::compressDirectory($workRoot, $tempZipPath);

            // 7. 通过 UploadService 存入 storage/backup/ 目录（不限制文件大小）
            $fileModel = UploadService::saveLocalFile($tempZipPath, [
                'name' => $fileName,
                'pid' => 0,
                'is_lock' => 1,
                'skip_size_check' => true,
                'dirname' => 'backup',
                'type_dir' => '', // 备份固定目录，不追加文件类型层
                'delete_source' => true,
                'extends' => [
                    'source' => 'system_backup',
                    'app_version' => $version,
                ],
            ]);

            // 8. 写入备份记录
            $record = new Backup();
            $record->save([
                'app_name' => 'system',
                'title' => 'Atrman 系统备份',
                'version' => $version,
                'backup_type' => Backup::TYPE_SYSTEM,
                'file_id' => $fileModel->id,
                'file_count' => FileService::countFiles($workRoot),
                'database_summary' => self::collectSystemDatabaseSummary(),
                'plugin_snapshot' => $pluginList,
                'manifest' => $backupManifest,
                'status' => 1,
                'error_message' => '',
            ]);

            self::linkFileToBackup($fileModel, $record);

            return $record;
        } catch (\Throwable $e) {
            self::rollbackFailedBackup($record, $fileModel);
            throw $e;
        } finally {
            // 清理临时文件
            if (is_file($tempZipPath)) {
                @unlink($tempZipPath);
            }
            if (is_dir($workRoot)) {
                Directory::removeDirWithin($workRoot, self::systemBackupWorkRoot());
            }
        }
    }

    /** 系统恢复前兼容性检查。 */
    public static function checkSystemCompatibility(array $backupManifest): array
    {
        $appManifest = self::readAppManifest();
        $currentVersion = (string)($appManifest['version'] ?? '1.0.0');
        // 兼容两种格式：backupManifest 用 app_version，appManifest 用 version
        $backupVersion = (string)($backupManifest['app_version'] ?? $backupManifest['version'] ?? '');
        $errors = [];
        $warnings = [];

        if ($backupVersion === '') {
            $errors[] = '备份清单缺少版本信息';
        } else {
            $compare = version_compare($backupVersion, $currentVersion);
            if ($compare > 0) {
                $errors[] = '备份版本(' . $backupVersion . ')高于当前版本(' . $currentVersion . ')，不允许恢复';
            }
            $currentMajor = explode('.', $currentVersion)[0];
            $backupMajor = explode('.', $backupVersion)[0];
            if ($currentMajor !== $backupMajor) {
                $errors[] = '主版本号不一致，无法恢复';
            }
        }

        // PHP 大版本检查
        $currentPhpMajor = (string)PHP_MAJOR_VERSION;
        $backupPhpRaw = (string)($backupManifest['php_version'] ?? PHP_VERSION);
        // 兼容旧格式：如果是 ">=8.1" 这样的要求字符串，提取版本号
        $backupPhpVersion = preg_replace('/^[><=!]+\s*/', '', $backupPhpRaw);
        $backupPhpMajor = explode('.', $backupPhpVersion)[0];
        // 只有当提取后是有效数字时才检查
        if (is_numeric($backupPhpMajor) && $currentPhpMajor !== $backupPhpMajor) {
            $errors[] = 'PHP 大版本不兼容：备份时 PHP ' . $backupPhpRaw . '，当前 PHP ' . PHP_VERSION;
        }

        // 插件检查
        $backupPlugins = $backupManifest['plugin_list'] ?? [];
        $pluginRoot = FileService::pluginRoot();
        $currentPlugins = [];
        if (is_dir($pluginRoot)) {
            foreach (scandir($pluginRoot) ?: [] as $item) {
                if ($item !== '.' && $item !== '..' && is_dir($pluginRoot . '/' . $item)) {
                    $currentPlugins[] = $item;
                }
            }
        }
        $missingPlugins = array_diff($backupPlugins, $currentPlugins);
        if (!empty($missingPlugins)) {
            $warnings[] = '备份中包含以下插件，但当前系统未安装：' . implode(', ', $missingPlugins);
        }

        return [
            'compatible' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'current_version' => $currentVersion,
            'backup_version' => $backupVersion,
            'current_php' => PHP_VERSION,
            'backup_php' => $backupPhpVersion,
        ];
    }

    /** 从备份记录恢复系统。 */
    /**
     * 解析备份记录的本地绝对路径，基准恒为 public_path()。
     *
     * path 取自 File 记录：被写成绝对路径、盘符或含 .. 时，与 public_path() 拼接的结果会跳出 web 根。
     * 下载/恢复/删除三个消费点原先各写一套判定（realpath 前缀比对与 assertPathWithin 并存），
     * 统一收到这里，越界或非法一律返回 null，由调用方决定报错还是跳过。
     */
    public static function resolveBackupLocalPath(File $file): ?string
    {
        $relative = str_replace('\\', '/', ltrim((string)$file->path, '/'));
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..') || preg_match('/^[A-Za-z]:/', $relative)) {
            return null;
        }
        try {
            return Directory::assertPathWithin(public_path() . '/' . $relative, public_path());
        } catch (\Throwable) {
            return null;
        }
    }

    public static function restoreSystem(int $backupId, bool $skipSafetyBackup = false): array
    {
        $backup = Backup::findOrEmpty($backupId);
        if ($backup->isEmpty()) {
            throw new ApiException('备份记录不存在');
        }
        if ((int)$backup->backup_type !== Backup::TYPE_SYSTEM) {
            throw new ApiException('该备份不是系统备份');
        }
        if ((int)$backup->status !== 1) {
            throw new ApiException('备份状态异常，无法恢复');
        }

        $file = File::findOrEmpty($backup->file_id);
        if ($file->isEmpty()) {
            throw new ApiException('备份关联文件不存在');
        }

        // 获取备份文件的本地路径（UploadService 存储的路径格式为 /storage/backup/xxx.zip）
        $localPath = self::resolveBackupLocalPath($file);
        if ($localPath === null) {
            throw new ApiException('备份文件路径异常');
        }
        if (!is_file($localPath)) {
            throw new ApiException('备份文件不在本地储存中或已被删除');
        }

        return self::restoreSystemFromZip($localPath, $backupId, $skipSafetyBackup);
    }

    /** 从 zip 文件恢复系统。 */
    public static function restoreSystemFromZip(string $zipPath, ?int $backupId = null, bool $skipSafetyBackup = false): array
    {
        if (!is_file($zipPath)) {
            throw new ApiException('备份包不存在');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new ApiException('当前 PHP 环境未安装 ZipArchive 扩展');
        }

        $workRoot = self::systemBackupWorkRoot() . '/restore_' . uniqid();
        $extractDir = $workRoot . '/extract';
        Directory::ensureDir($extractDir);

        try {
            FileService::extractZipSafely($zipPath, $extractDir);

            // 读取备份清单
            $backupManifest = self::readBackupManifest($extractDir);

            // 兼容性检查
            $compatibility = self::checkSystemCompatibility($backupManifest);
            if (!$compatibility['compatible']) {
                throw new ApiException('备份不兼容：' . implode('; ', $compatibility['errors']));
            }

            // 恢复前自动备份当前系统（安全网）；回滚场景跳过，避免递归生成安全备份
            $safetyBackup = $skipSafetyBackup ? null : self::backupSystem();

            try {
                // 覆盖目录
                $dirsToRestore = ['plugin', 'config', 'vendor', 'public'];
                foreach ($dirsToRestore as $dir) {
                    $sourceDir = $extractDir . '/' . $dir;
                    $targetDir = base_path() . '/' . $dir;
                    if (is_dir($sourceDir)) {
                        if (is_dir($targetDir)) {
                            Directory::removeDir($targetDir);
                        }
                        Directory::copyDir($sourceDir, $targetDir);
                    }
                }

                // 合并 .env 密钥
                $envSkipped = [];
                $envBackupPath = $extractDir . '/env.backup';
                if (is_file($envBackupPath)) {
                    $envSkipped = self::mergeEnvForRestore($envBackupPath);
                }

                // 恢复 manifest.json
                $manifestSource = $extractDir . '/manifest.json';
                if (is_file($manifestSource)) {
                    copy($manifestSource, base_path() . '/manifest.json');
                }

                // 导入数据库
                $sqlPath = $extractDir . '/database.sql';
                if (is_file($sqlPath)) {
                    self::importSystemDatabase($sqlPath);
                }

                // 清理运行期缓存（与插件状态变更走同一套清理口径）
                PluginManager::clearRuntimeCaches();

                // 更新备份记录状态
                if ($backupId) {
                    $backup = Backup::find($backupId);
                    if ($backup) {
                        $backup->save(['status' => 1]);
                    }
                }

                return [
                    'restored' => true,
                    'backup_version' => $backupManifest['app_version'] ?? '',
                    'safety_backup_id' => $safetyBackup ? $safetyBackup->id : null,
                    // 让「数据库/Redis 指向没跟过来」是明确提示而不是事后才发现
                    'warnings' => $envSkipped
                        ? array_merge($compatibility['warnings'], [
                            '备份中的 ' . count($envSkipped) . ' 个环境键未随恢复写入（'
                                . implode(', ', array_slice($envSkipped, 0, 6))
                                . (count($envSkipped) > 6 ? ' 等' : '')
                                . '），DB_/REDIS_/CACHE_ 一律取本机 .env',
                        ])
                        : $compatibility['warnings'],
                    'message' => '系统恢复成功，请重启服务使新配置生效',
                ];
            } catch (\Throwable $e) {
                Log::error('系统恢复失败：' . $e->getMessage() . ($backupId ? '（恢复来源备份 id=' . $backupId . '）' : ''));
                // 恢复失败，尝试回滚到恢复前的安全备份
                if ($safetyBackup) {
                    $rollbackFailed = null;
                    try {
                        // 回滚不再创建新的安全备份，防止递归链
                        self::restoreSystem((int)$safetyBackup->id, true);
                    } catch (\Throwable $rollbackError) {
                        $rollbackFailed = $rollbackError;
                        Log::error('系统恢复回滚失败，需人工处理。safety_backup_id=' . $safetyBackup->id . '；原因：' . $rollbackError->getMessage());
                    }
                    if ($rollbackFailed === null) {
                        throw new ApiException('恢复失败，已回滚至恢复前状态：' . $e->getMessage());
                    }
                    throw new ApiException('恢复失败且回滚未完成，可手动恢复安全备份 #' . $safetyBackup->id . '：' . $e->getMessage());
                }
                throw new ApiException('恢复失败（无安全备份可回滚）：' . $e->getMessage());
            }
        } finally {
            if (is_dir($workRoot)) {
                Directory::removeDirWithin($workRoot, self::systemBackupWorkRoot());
            }
        }
    }

    /** 获取 MySQL 版本。 */
    protected static function getMysqlVersion(): string
    {
        try {
            $result = Db::query('SELECT VERSION() as version');
            return (string)($result[0]['version'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function linkFileToBackup(File $fileModel, Backup $record): void
    {
        $extends = $fileModel->extends ?: [];
        $extends['backup_id'] = $record->id;
        $fileModel->save(['extends' => $extends]);
    }

    private static function rollbackFailedBackup(?Backup $record, ?File $fileModel): void
    {
        if ($record) {
            $record->delete();
        }
        if ($fileModel) {
            $fileModel->delete();
        }
    }
}
