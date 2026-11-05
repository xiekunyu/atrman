<?php

namespace app\basic;

use support\Log;
use support\Redis;

/**
 * 已吊销令牌的 jti 名单。
 *
 * access 令牌是纯 JWT，验签通过即有效，签发后本来没有任何撤回手段：
 * 共享电脑登录、令牌泄漏都只能等 exp 自然到期。这里在签发时往载荷塞 jti，
 * 校验时查一次名单，退出登录时按 jti 写入名单。
 *
 * access 与 refresh 由同一份 extend 签出（tinywan/jwt 的 generatePayload 把同一个 extend
 * 装进两类载荷），所以一个 jti 同时代表这一对令牌——吊销一次即双双失效，
 * 名单 TTL 因此按 refresh 的窗口留存，否则 access 先过期、refresh 还能换回新令牌。
 */
class JwtRevoker
{
    /** 名单键前缀（全站 Redis 键统一带 atrman: 前缀） */
    private const KEY_PREFIX = 'atrman:jwt:revoked:';

    /**
     * 令牌是否已被吊销。
     * 空 jti 一律视为未吊销：存量会话与老版本签发的令牌都没有这个字段，
     * 若按「查不到就拒」处理，升级这次改动会把所有已登录的人当场踢下线。
     */
    public static function isRevoked(string $jti): bool
    {
        if ($jti === '') {
            return false;
        }
        try {
            return (bool)Redis::exists(self::KEY_PREFIX . $jti);
        } catch (\Throwable $e) {
            // Redis 故障时放行，与登录失败计数同一口径：缓存问题不该把已登录的管理员关在门外
            Log::warning('[JwtRevoker] 吊销名单读取失败：' . $e->getMessage());
            return false;
        }
    }

    /** 吊销当前令牌（对应的 refresh 令牌一并失效）。 */
    public static function revoke(string $jti): void
    {
        if ($jti === '') {
            return;
        }
        $ttl = max(60, (int)config('plugin.tinywan.jwt.app.jwt.refresh_exp', 604800));
        try {
            Redis::set(self::KEY_PREFIX . $jti, 1, 'EX', $ttl);
        } catch (\Throwable $e) {
            // 写不进去就不能假装已退出：名单落不下时令牌仍然有效，必须留下痕迹便于排查
            Log::error('[JwtRevoker] 吊销名单写入失败，令牌在到期前仍可用：' . $e->getMessage());
        }
    }
}
