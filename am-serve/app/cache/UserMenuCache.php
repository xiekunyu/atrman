<?php

namespace app\cache;

use app\model\system\Menu;

class UserMenuCache
{
    public static function getUserMenu(int $uid): array
    {
        $cache = CacheManager::config('menu');
        $key = ($cache['prefix'] ?? 'atrman:menu:user_') . $uid;
        return CacheManager::remember($key, $cache['expire'] ?? 604800, fn() => self::setUserMenu($uid), $cache['tag'] ?? 'atrman:menu');
    }

    public static function setUserMenu(int $uid): array
    {
        $model = new Menu();
        if (isSuperAdmin($uid)) {
            return $model->getAuthMenus([], true);
        }
        return $model->getAuthMenus(UserAuthCache::roleIds($uid));
    }

    public static function clearUserMenu(int|array $uid): bool
    {
        $cache = CacheManager::config('menu');
        return CacheManager::clearKeys($cache['prefix'] ?? 'atrman:menu:user_', $uid);
    }

    public static function clear(): bool
    {
        $cache = CacheManager::config('menu');
        return CacheManager::clearTag($cache['tag'] ?? 'atrman:menu');
    }
}
