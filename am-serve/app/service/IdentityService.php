<?php

namespace app\service;

use app\basic\RegistryGuard;
use app\cache\CacheManager;
use app\model\system\File;
use app\model\system\User;
use app\utils\Hashids;
use support\think\Cache;

/**
 * 统一身份解析服务（IdentityService）
 *
 * 职责：给定任意 sid，批量解析出用于展示/IM 渲染的最小安全资料（出口白名单，
 * 不含 password/salt/email/mobile 等敏感字段）。
 *
 * 扩展机制（为后续会员中心 / IM 等插件预留）：
 * - registerProvider($type, $resolver)：注册某种 sid 前缀（如 'grp'/'bot'/未来自定义）的
 *   资料解析器，回调签名 fn(array $sids): array[sid => data]。'sys'(人) 由基座内置解析。
 * - registerExtender($callable)：对已解析出的每条资料做补充（如会员中心追加会员等级等
 *   非敏感字段），回调签名 fn(array $item): array，返回合并后的条目。
 * 未注册对应 provider 的未知前缀会被安全跳过，不影响基座运行。
 */
class IdentityService
{
    /** 人员资料缓存前缀（独立于用户信息缓存，避免键冲突） */
    protected const CACHE_PREFIX = 'atrman:identity:person_';

    /** 未命中占位缓存 TTL（秒），防止缓存穿透 */
    protected const MISS_TTL = 60;

    /** @var array<string, array{resolver: callable, owner: string}> 按主体前缀注册的解析器（带 owner，供 forgetByOwner 对称清理） */
    protected static array $providers = [];

    /** @var list<array{extender: callable, owner: string}> 解析结果扩展器（带 owner） */
    protected static array $extenders = [];

    /**
     * 注册某前缀（主体类型）的资料解析器。
     * 回调签名：fn(array $sids): array  // [sid => ['sid','type','name','avatar','status',...]]
     *
     * @param string $owner 归属插件名（插件注册必传，卸载/禁用对称清理用）
     */
    public static function registerProvider(string $type, callable $resolver, string $owner = ''): void
    {
        RegistryGuard::assertWritable(self::class, 'registerProvider');
        self::$providers[$type] = ['resolver' => $resolver, 'owner' => $owner];
    }

    public static function hasProvider(string $type): bool
    {
        return isset(self::$providers[$type]);
    }

    /**
     * 注册解析扩展器，用于会员中心等插件给已解析条目追加安全字段。
     * 回调签名：fn(array $item): array
     *
     * @param string $owner 归属插件名
     */
    public static function registerExtender(callable $extender, string $owner = ''): void
    {
        RegistryGuard::assertWritable(self::class, 'registerExtender');
        self::$extenders[] = ['extender' => $extender, 'owner' => $owner];
    }

    /**
     * 按 owner 清理某插件注册的全部 provider / extender（禁用/卸载时由 PluginManager 调用）
     */
    public static function forgetByOwner(string $owner): void
    {
        if ($owner === '') {
            return;
        }
        foreach (self::$providers as $type => $entry) {
            if ($entry['owner'] === $owner) {
                unset(self::$providers[$type]);
            }
        }
        self::$extenders = array_values(array_filter(
            self::$extenders,
            fn($entry) => $entry['owner'] !== $owner
        ));
    }

    /**
     * 批量按 sid 解析主体资料。返回 [sid => data]，无法解析的 sid 不出现在结果中。
     *
     * @param string[] $sids
     */
    public static function resolve(array $sids): array
    {
        $sids = array_values(array_unique(array_filter($sids)));
        if (!$sids) {
            return [];
        }

        // 按前缀分组路由
        $groups = [];
        foreach ($sids as $sid) {
            $groups[Hashids::typeOf($sid)][] = $sid;
        }

        $result = [];
        foreach ($groups as $type => $groupSids) {
            if ($type === Hashids::TYPE_PERSON) {
                $result += self::resolvePersons($groupSids);
            } elseif (isset(self::$providers[$type])) {
                $data = call_user_func(self::$providers[$type]['resolver'], $groupSids);
                if (is_array($data)) {
                    foreach ($data as $k => $v) {
                        $result[$k] = $v;
                    }
                }
            }
            // 未知前缀且无 provider：跳过（预留扩展点）
        }

        // 应用扩展器（会员中心等）
        if (self::$extenders) {
            foreach ($result as $sid => $item) {
                foreach (self::$extenders as $entry) {
                    $merged = call_user_func($entry['extender'], $item);
                    if (is_array($merged)) {
                        $item = $merged;
                    }
                }
                $result[$sid] = $item;
            }
        }

        return $result;
    }

    /**
     * 解析单个 sid。
     */
    public static function resolveOne(?string $sid): ?array
    {
        if (!$sid) {
            return null;
        }
        $map = self::resolve([$sid]);
        return $map[$sid] ?? null;
    }

    /**
     * 人员主体内置解析：读统一主体表 system_user，走 Redis 缓存，只返回白名单字段。
     *
     * @param string[] $sids
     */
    protected static function resolvePersons(array $sids): array
    {
        $out = [];
        $miss = [];
        $cacheConfig = CacheManager::config('user');
        $ttl = (int)($cacheConfig['expire'] ?? 14400);

        foreach ($sids as $sid) {
            $cached = Cache::get(self::CACHE_PREFIX . $sid);
            if (is_array($cached)) {
                $out[$sid] = $cached;
            } else {
                $miss[] = $sid;
            }
        }

        if (!$miss) {
            return $out;
        }

        $users = User::whereIn('sid', $miss)
            ->field('id,sid,nickname,username,avatar_id,status,account_type')
            ->select()
            ->toArray();

        // 批量解析头像 URL（url + path）
        $avatarIds = array_values(array_filter(array_column($users, 'avatar_id')));
        $avatarMap = [];
        if ($avatarIds) {
            $files = File::whereIn('id', $avatarIds)->field('id,url,path,thumb')->select()->toArray();
            foreach ($files as $f) {
                $avatarMap[$f['id']] = ($f['url'] ?? '') . ($f['path'] ?? '');
            }
        }

        $found = [];
        foreach ($users as $u) {
            $data = [
                'sid' => $u['sid'],
                'type' => Hashids::TYPE_PERSON,
                'name' => ($u['nickname'] !== '' && $u['nickname'] !== null) ? $u['nickname'] : $u['username'],
                'avatar' => $avatarMap[$u['avatar_id']] ?? '',
                'status' => (int)$u['status'],
                'account_type' => (int)($u['account_type'] ?? User::ACCOUNT_ADMIN),
            ];
            $out[$u['sid']] = $data;
            $found[$u['sid']] = true;
            Cache::set(self::CACHE_PREFIX . $u['sid'], $data, $ttl);
        }

        // 未找到的 sid 记短 TTL 空占位，防止穿透
        foreach ($miss as $sid) {
            if (!isset($found[$sid])) {
                $empty = ['sid' => $sid, 'type' => Hashids::TYPE_PERSON, 'name' => '', 'avatar' => '', 'status' => 0];
                $out[$sid] = $empty;
                Cache::set(self::CACHE_PREFIX . $sid, $empty, self::MISS_TTL);
            }
        }

        return $out;
    }

    /**
     * 清理某个人员的资料缓存（改名/换头像/状态变更后调用）。
     */
    public static function clearPerson(string $sid): void
    {
        Cache::delete(self::CACHE_PREFIX . $sid);
    }

    /**
     * 跨人员主体按关键字查找（昵称/用户名），返回最小展示资料。
     * 供基座及后续 IM 添加联系人使用。
     *
     * @return array<int, array>
     */
    public static function lookupPerson(string $keyword, int $limit = 20): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        $users = User::where('nickname|username', 'like', '%' . escapeLike($keyword) . '%')
            ->field('id,sid,nickname,username,avatar_id,status,account_type')
            ->limit($limit)
            ->select()
            ->toArray();
        return array_map(fn($u) => [
            'sid' => $u['sid'],
            'type' => Hashids::TYPE_PERSON,
            'name' => ($u['nickname'] !== '' && $u['nickname'] !== null) ? $u['nickname'] : $u['username'],
            'status' => (int)$u['status'],
            'account_type' => (int)($u['account_type'] ?? User::ACCOUNT_ADMIN),
        ], $users);
    }
}
