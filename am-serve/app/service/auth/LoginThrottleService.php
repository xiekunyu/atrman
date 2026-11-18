<?php

namespace app\service\auth;

use app\exception\ApiException;
use support\Log;
use support\Redis;

/**
 * 登录防爆破公共服务（基座命名空间，供基座与 am_store 等插件共用）。
 *
 * 规则：
 * - IP + 账号双维度独立计数，任一维度在 600s 窗口内失败达到 10 次即进入锁定态；
 * - 锁定态不直接封死，而是要求调用方强制校验图形验证码（人机校验通过后放行当次请求）；
 * - 计数键不存账号明文，账号维度用 md5 小写归一化后入键；
 * - Redis 故障一律 fail-open：计数读不到视为未锁定、写失败静默跳过，避免缓存组件故障
 *   把合法用户锁在门外；但每次降级都必须留 warning 日志，限流失效要能被监控发现。
 */
class LoginThrottleService
{
    /** 窗口内允许的连续失败次数 */
    public const FAIL_LIMIT = 10;

    /** 失败计数窗口（秒）：10 分钟 */
    public const FAIL_WINDOW = 600;

    /** 锁定态统一文案：不暴露命中的是 IP 还是账号维度，防枚举 */
    public const LOCK_MESSAGE = '失败次数过多，请完成图形验证码后重试或 10 分钟后再试';

    private const IP_KEY_PREFIX = 'atrman:throttle:login:ip:';

    private const ACCT_KEY_PREFIX = 'atrman:throttle:login:acct:';

    private const RATE_KEY_PREFIX = 'atrman:throttle:rate:';

    /**
     * 是否处于锁定态（IP 或账号任一维度达阈值）。Redis 故障时 fail-open 返回 false。
     */
    public static function isLocked(string $scene, string $ip, string $account): bool
    {
        if (self::count(self::ipKey($scene, $ip)) >= self::FAIL_LIMIT) {
            return true;
        }
        $accountKey = self::accountKey($scene, $account);
        return $accountKey !== '' && self::count($accountKey) >= self::FAIL_LIMIT;
    }

    /**
     * 记一次登录失败：IP 与账号两个维度各自 incr，首次计数落窗口 TTL。
     */
    public static function bump(string $scene, string $ip, string $account): void
    {
        self::incr(self::ipKey($scene, $ip));
        $accountKey = self::accountKey($scene, $account);
        if ($accountKey !== '') {
            self::incr($accountKey);
        }
    }

    /**
     * 登录成功后清掉两个维度的失败计数。
     */
    public static function clear(string $scene, string $ip, string $account): void
    {
        try {
            $keys = [self::ipKey($scene, $ip)];
            $accountKey = self::accountKey($scene, $account);
            if ($accountKey !== '') {
                $keys[] = $accountKey;
            }
            Redis::del($keys);
        } catch (\Throwable $e) {
            Log::warning('[登录防爆破] 失败计数清理失败（fail-open，不影响登录）：' . $e->getMessage());
        }
    }

    /**
     * 验证码等公开端点的纯 IP 滑动窗口频控：$window 秒内最多 $max 次，超限抛 429。
     * Redis 故障时 fail-open 放行并记 warning（公开端点不能因缓存故障整体不可用）。
     */
    public static function assertIpRateLimited(string $scene, string $ip, int $max = 10, int $window = 60): void
    {
        $key = self::RATE_KEY_PREFIX . $scene . ':ip:' . $ip;
        try {
            $count = self::incrWithTtl($key, $window);
            if ($count > $max) {
                throw new ApiException('请求过于频繁，请 ' . $window . ' 秒后再试', 429);
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('[登录防爆破] 端点频控计数失败（fail-open，scene=' . $scene . '）：' . $e->getMessage());
        }
    }

    private static function count(string $key): int
    {
        try {
            return (int)Redis::get($key);
        } catch (\Throwable $e) {
            Log::warning('[登录防爆破] 失败计数读取失败（fail-open 视为 0）：' . $e->getMessage());
            return 0;
        }
    }

    private static function incr(string $key): void
    {
        try {
            self::incrWithTtl($key, self::FAIL_WINDOW);
        } catch (\Throwable $e) {
            Log::warning('[登录防爆破] 失败计数写入失败（fail-open，本次失败不计入）：' . $e->getMessage());
        }
    }

    /**
     * incr 并保证键有 TTL：incr 成功但 expire 没跟上会留下永不过期的键，
     * 把用户/IP 永久锁死，故对 ttl<0（无过期时间）补一次 expire 修复。
     */
    private static function incrWithTtl(string $key, int $ttl): int
    {
        $count = (int)Redis::incr($key);
        if ($count === 1 || (int)Redis::ttl($key) < 0) {
            Redis::expire($key, $ttl);
        }
        return $count;
    }

    private static function ipKey(string $scene, string $ip): string
    {
        return self::IP_KEY_PREFIX . $scene . ':' . $ip;
    }

    private static function accountKey(string $scene, string $account): string
    {
        $account = mb_strtolower(trim($account));
        if ($account === '') {
            return '';
        }
        return self::ACCT_KEY_PREFIX . $scene . ':' . md5($account);
    }
}
