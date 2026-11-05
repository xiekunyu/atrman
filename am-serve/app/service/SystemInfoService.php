<?php

namespace app\service;

use app\model\plugin\App as Plugin;
use Composer\InstalledVersions;
use support\Redis;
use support\think\Db;

/**
 * 系统信息聚合：主程序自身信息（版本/环境/组件/插件统计），
 * 与 ServerMonitor（CPU/内存/磁盘资源监控）职责区分。
 *
 * 脱敏铁律：绝不返回 .env 明文、DB/Redis 密码、JWT secret、服务器绝对路径；
 * 只输出展示所需的信息项，单项取数失败以空值降级、不阻断整页。
 */
class SystemInfoService
{
    /** 关键 composer 包版本清单（包名 => 展示名） */
    private const PACKAGES = [
        'workerman/workerman' => 'Workerman',
        'workerman/webman-framework' => 'Webman',
        'topthink/think-orm' => 'ThinkORM',
        'guzzlehttp/guzzle' => 'Guzzle',
    ];

    /** 关注的基础扩展（未加载时前端显示未安装） */
    private const EXTENSIONS = [
        'pdo_mysql', 'zip', 'curl', 'mbstring', 'openssl', 'sodium', 'pcntl', 'posix',
    ];

    /**
     * 汇总系统信息。
     * @return array{app:array,php:array,components:array,database:array,redis:array,plugins:array,update:array}
     */
    public static function index(): array
    {
        return [
            'app' => self::appInfo(),
            'php' => self::phpInfo(),
            'components' => self::components(),
            'database' => self::database(),
            'redis' => self::redis(),
            'plugins' => self::plugins(),
            'update' => self::update(),
        ];
    }

    /** 主程序版本与运行参数（版本真源：根目录 manifest.json） */
    private static function appInfo(): array
    {
        $manifest = [];
        try {
            $manifest = BackupService::readAppManifest();
        } catch (\Throwable) {
            // manifest.json 缺失不阻断
        }
        return [
            'name' => (string)($manifest['name'] ?? 'atrman'),
            'title' => (string)($manifest['title'] ?? 'Atrman'),
            'version' => PluginManifestService::getAppVersion(),
            'release' => (string)($manifest['release'] ?? ''),
            'table_prefix' => (string)config('database.connections.mysql.prefix', ''),
            'timezone' => (string)config('app.default_timezone', ''),
            'debug' => (bool)config('app.debug', false),
            'env' => (string)env('APP_ENV', 'production'),
        ];
    }

    /** PHP 与系统环境（不含绝对路径） */
    private static function phpInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'os' => trim(php_uname('s') . ' ' . php_uname('r')),
            'sapi' => PHP_SAPI,
            'memory_limit' => (string)ini_get('memory_limit'),
            'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
            'post_max_size' => (string)ini_get('post_max_size'),
            'max_execution_time' => (string)ini_get('max_execution_time'),
            'extensions' => array_map(
                fn(string $ext) => ['name' => $ext, 'loaded' => (int)extension_loaded($ext)],
                self::EXTENSIONS
            ),
        ];
    }

    /** 关键 composer 包版本（InstalledVersions 随 autoload 生成，缺失时以 composer.lock 兜底） */
    private static function components(): array
    {
        $out = [];
        foreach (self::PACKAGES as $package => $label) {
            $version = '';
            try {
                if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                    $version = (string)InstalledVersions::getPrettyVersion($package);
                }
            } catch (\Throwable) {
                // 单包取数失败降级为空
            }
            $out[] = ['label' => $label, 'package' => $package, 'version' => $version];
        }
        return $out;
    }

    /** MySQL 版本（复用 ServerMonitor 的连接口径，只回版本不回连接串） */
    private static function database(): array
    {
        $version = '';
        try {
            $row = Db::query('SELECT VERSION() as version');
            $version = (string)($row[0]['version'] ?? '');
        } catch (\Throwable) {
            // 查询失败保持空值
        }
        return [
            'driver' => (string)config('database.connections.mysql.type', 'mysql'),
            'version' => $version,
            'prefix' => (string)config('database.connections.mysql.prefix', ''),
        ];
    }

    /** Redis 版本与库名（INFO server 只取 redis_version，不泄露地址） */
    private static function redis(): array
    {
        $version = '';
        try {
            $info = Redis::info('server');
            $version = (string)(is_array($info) ? ($info['redis_version'] ?? '') : '');
        } catch (\Throwable) {
            // Redis 不可用降级为空
        }
        return [
            'version' => $version,
            'database' => (int)config('redis.database', 0),
        ];
    }

    /** 插件应用统计（status：0草稿/禁用 1启用，语义与 Plugin 模型一致） */
    private static function plugins(): array
    {
        return [
            'total' => Plugin::count(),
            'enabled' => Plugin::where('status', 1)->count(),
        ];
    }

    /** 主程序更新角标：检查失败不阻断整页（网络异常按无更新展示） */
    private static function update(): array
    {
        try {
            $check = StoreService::checkSystemUpdate();
        } catch (\Throwable) {
            $check = null;
        }
        return [
            'enabled' => StoreService::systemUpdateEnabled(),
            'has_update' => (bool)($check['has_update'] ?? false),
            'latest_version' => (string)($check['latest_version'] ?? ''),
        ];
    }
}
