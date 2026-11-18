<?php

namespace app\service\auth;

use support\Log;
use support\Redis;

/**
 * 框架级限流计数引擎（固定窗口）。
 *
 * 与 LoginThrottleService 同口径：INCR + 首包 TTL，ttl<0 补 expire 防止永不过期的键
 * 把身份永久锁死。Redis 故障一律 fail-open 放行，但每次降级必留 warning，
 * 限流失效要能被监控发现。固定窗口存在临界 2 倍突发，框架级防护可接受；
 * 若后续有精算需求再换 Lua 滑动窗口（eval 写法：eval($script, $numKeys, ...$args)）。
 */
class RateLimiter
{
    private const KEY_PREFIX = 'atrman:ratelimit:';

    /**
     * 计入一次请求并判定是否超限。
     *
     * @return array{limited: bool, retry_after: int} limited=是否拦截；retry_after=建议重试等待秒数
     */
    public static function check(string $scene, string $identity, int $max, int $window): array
    {
        $key = self::KEY_PREFIX . $scene . ':' . $identity;
        try {
            $count = (int) Redis::incr($key);
            // incr 成功但 expire 没跟上会留下永不过期的键，对 ttl<0（无过期时间）补一次 expire
            if ($count === 1 || (int) Redis::ttl($key) < 0) {
                Redis::expire($key, $window);
            }
            if ($count > $max) {
                $ttl = (int) Redis::ttl($key);
                return [
                    'limited' => true,
                    'retry_after' => $ttl > 0 ? $ttl : $window,
                ];
            }
            return ['limited' => false, 'retry_after' => 0];
        } catch (\Throwable $e) {
            Log::warning('[全局限流] Redis 计数失败（fail-open 放行，scene=' . $scene . '）：' . $e->getMessage());
            return ['limited' => false, 'retry_after' => 0];
        }
    }
}
