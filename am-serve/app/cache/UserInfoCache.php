<?php

namespace app\cache;

use app\model\system\Dept;
use app\model\system\DeptUser;
use app\model\system\User;

class UserInfoCache
{
    public static function getUserInfo(int $uid): array
    {
        $cache = CacheManager::config('user');
        $key = ($cache['prefix'] ?? 'atrman:user:info_') . $uid;
        return CacheManager::remember($key, $cache['expire'] ?? 14400, fn() => self::setUserInfo($uid), self::tags($uid));
    }

    public static function setUserInfo(int $uid): array
    {
        $user = User::with(['avatar', 'roles'])->findOrEmpty($uid);
        if (method_exists($user, 'isEmpty') && $user->isEmpty()) {
            return [];
        }

        $userData = $user->hidden(['password', 'salt'])->toArray();
        // 只认启用角色：与 UserAuthCache::roleIds / Menu 的鉴权口径一致，
        // 否则角色禁用后其 data_scope=全部 与 level 仍会从这份缓存里生效
        $roles = $user->roles ? $user->roles->where('status', 1) : null;
        $roleIds = $roles ? $roles->column('id') : [];
        $userData['role_ids'] = $roleIds;
        // roles 带 level/data_scope：供行政级别与数据权限解析（见 DataScopeService）
        $userData['roles'] = $roles ? $roles->visible(['id', 'name', 'code', 'level', 'data_scope'])->toArray() : [];
        // 我的行政级别 = 所有角色 level 最大值（Collection 无 max()，取列后用原生 max）
        $levels = $roles ? array_map('intval', $roles->column('level')) : [];
        $userData['role_level'] = $levels ? max($levels) : 0;
        $userData['buttons'] = UserAuthCache::getUserAuth($uid);

        $deptUsers = DeptUser::where('user_id', $uid)->select()->toArray();
        $deptIds = array_column($deptUsers, 'dept_id');
        $deptMap = [];
        if (!empty($deptIds)) {
            $deptList = Dept::whereIn('id', $deptIds)->field('id,name')->select()->toArray();
            foreach ($deptList as $dept) {
                $deptMap[$dept['id']] = $dept;
            }
        }

        $userData['dept_ids'] = $deptIds;
        $userData['dept_items'] = [];
        $userData['depts'] = [];
        foreach ($deptUsers as $deptUser) {
            $dept = $deptMap[$deptUser['dept_id']] ?? [];
            if (!$dept) {
                continue;
            }
            $dept['is_main'] = (int) ($deptUser['is_main'] ?? 0);
            $dept['stype'] = 'dept';
            $dept['key'] = 'dept_' . $dept['id'];
            $userData['depts'][] = $dept;
            $userData['dept_items'][] = ['id' => $dept['id'], 'is_main' => $dept['is_main']];
        }
        $userData['department'] = $userData['depts'];

        return $userData;
    }

    public static function clearUserInfo(int|array $uid): bool
    {
        $cache = CacheManager::config('user');
        return CacheManager::clearKeys($cache['prefix'] ?? 'atrman:user:info_', $uid);
    }

    public static function clearByRole(int|array $roleIds): bool
    {
        $cache = CacheManager::config('user');
        return CacheManager::clearTags($cache['role'] ?? 'atrman:user:role_', $roleIds);
    }

    public static function clearByDept(int|array $deptIds): bool
    {
        $cache = CacheManager::config('user');
        return CacheManager::clearTags($cache['dept'] ?? 'atrman:user:dept_', $deptIds);
    }

    public static function clearUserAll(int|array $uid): bool
    {
        UserAuthCache::clearUserAuth($uid);
        UserMenuCache::clearUserMenu($uid);
        return self::clearUserInfo($uid);
    }

    public static function clear(): bool
    {
        $cache = CacheManager::config('user');
        return CacheManager::clearTag($cache['tag'] ?? 'atrman:user');
    }

    private static function tags(int $uid): array
    {
        $cache = CacheManager::config('user');
        $tags = [$cache['tag'] ?? 'atrman:user'];
        // 标签按「含禁用」登记：缓存内容只认启用角色，但禁用角色也要能在被重新启用时清掉这份缓存
        foreach (UserAuthCache::roleIds($uid, true) as $roleId) {
            $tags[] = ($cache['role'] ?? 'atrman:user:role_') . $roleId;
        }
        foreach (DeptUser::where('user_id', $uid)->column('dept_id') as $deptId) {
            $tags[] = ($cache['dept'] ?? 'atrman:user:dept_') . $deptId;
        }
        return $tags;
    }
}
