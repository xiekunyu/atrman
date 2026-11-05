<?php

namespace app\cache;

use app\model\system\Config;

class ConfigCache
{
    /**
     * 读取配置组（带缓存）。
     *
     * @param string $code    配置组编码
     * @param string $appName 归属应用；留空或 system 视为基座配置，插件传入自身插件名以区分同名配置组
     */
    public static function getConfig(string $code, string $appName = ''): array
    {
        $cache = CacheManager::config('config');
        $key = self::key($code, $appName);
        return CacheManager::remember($key, $cache['expire'] ?? 31536000, fn() => self::setConfig($code, $appName), $cache['tag'] ?? 'atrman:config');
    }

    public static function setConfig(string $code, string $appName = ''): array
    {
        return Config::getConfigFromDb($code, $appName);
    }

    public static function clearConfig(string $code, string $appName = ''): bool
    {
        return CacheManager::clearKey(self::key($code, $appName));
    }

    public static function clear(): bool
    {
        $cache = CacheManager::config('config');
        return CacheManager::clearTag($cache['tag'] ?? 'atrman:config');
    }

    private static function key(string $code, string $appName = ''): string
    {
        $cache = CacheManager::config('config');
        return ($cache['prefix'] ?? 'atrman:config:group_') . md5($code . '|' . self::normalizeAppName($appName));
    }

    /**
     * 归一化配置归属：空值与系统内置(system) 归入基座桶（''），其余为插件名。
     * 读写与清缓存均经此归一，保证同名配置组在不同归属下缓存键一致、互不串档。
     */
    public static function normalizeAppName(string $appName): string
    {
        $appName = trim($appName);
        return ($appName === '' || $appName === 'system') ? '' : $appName;
    }
}
