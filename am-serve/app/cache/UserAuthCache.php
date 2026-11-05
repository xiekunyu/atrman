<?php

namespace app\cache;

use app\model\system\Menu;
use app\model\system\User;

class UserAuthCache
{
    /** 超管权限哨兵：与按钮 slug 同为数组，前端 v-auth 直接 includes('*') 判定 */
    public const SUPER_AUTH = ['*'];

    public static function getUserAuth(int $uid): array
    {
        if (isSuperAdmin($uid)) {
            return self::SUPER_AUTH;
        }
        $cache = CacheManager::config('auth');
        $key = ($cache['prefix'] ?? 'atrman:auth:user_') . $uid;
        return CacheManager::remember($key, $cache['expire'] ?? 7200, fn() => self::setUserAuth($uid), self::tags($uid));
    }

    public static function setUserAuth(int $uid): array
    {
        if (isSuperAdmin($uid)) {
            return self::SUPER_AUTH;
        }
        $roleIds = self::roleIds($uid);
        if (!$roleIds) {
            return [];
        }
        return Menu::getRoleMenus($roleIds);
    }

    public static function clearUserAuth(int|array $uid): bool
    {
        $cache = CacheManager::config('auth');
        return CacheManager::clearKeys($cache['prefix'] ?? 'atrman:auth:user_', $uid);
    }

    public static function clearByRole(int|array $roleIds): bool
    {
        $cache = CacheManager::config('auth');
        return CacheManager::clearTags($cache['role'] ?? 'atrman:auth:role_', $roleIds);
    }

    public static function clear(): bool
    {
        $cache = CacheManager::config('auth');
        return CacheManager::clearTag($cache['tag'] ?? 'atrman:auth');
    }

    private static function tags(int $uid): array
    {
        $cache = CacheManager::config('auth');
        $tags = [$cache['tag'] ?? 'atrman:auth'];
        foreach (self::roleIds($uid, true) as $roleId) {
            $tags[] = ($cache['role'] ?? 'atrman:auth:role_') . $roleId;
        }
        return $tags;
    }

    /**
     * 取用户角色 ID。
     *
     * @param bool $withDisabled 是否包含已禁用角色：鉴权/菜单/数据权限一律只认启用角色（默认 false）；
     *  登记缓存标签时必须传 true —— 否则角色禁用期间回填的缓存不带该角色标签，
     *  之后「启用角色」的 clearByRole 对这些用户是空操作，最长 4 小时不生效。
     */
    public static function roleIds(int $uid, bool $withDisabled = false): array
    {
        $user = User::with(['roles'])->findOrEmpty($uid);
        if (method_exists($user, 'isEmpty') && $user->isEmpty()) {
            return [];
        }
        if (!$user->roles) {
            return [];
        }
        $roles = $withDisabled ? $user->roles : $user->roles->where('status', 1);
        return $roles ? $roles->column('id') : [];
    }
}
