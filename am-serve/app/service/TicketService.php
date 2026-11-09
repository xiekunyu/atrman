<?php

namespace app\service;

use app\cache\CacheManager;
use app\exception\ApiException;
use support\Redis;

/**
 * 免登票据服务（基座侧）
 *
 * 用途：基座为"当前登录主体"签发一次性 ticket，透传给应用（entry / manage_entry）；
 * 应用后端用 ticket 换取会话，拿到登录主体的 sid。此后应用内的权限判定完全交给应用自身，
 * 基座不介入应用内权限，只保证：
 *  - ticket 由基座密钥 HMAC-SHA256 签名，不可伪造；
 *  - 绑定主体 sid 与目标应用 aud，短期有效；
 *  - 基于 Redis 的 jti 原子消费，一票据只能用一次（防重放）。
 *
 * 注意：本服务只负责身份透传，不代表任何"应用内角色/权限"。
 */
class TicketService
{
    /** jti 消费记录 Redis 键前缀 */
    protected const NONCE_PREFIX = 'atrman:ticket:jti_';

    /** 默认时效（秒），可由 cache_manage.ticket.expire 覆盖 */
    protected const DEFAULT_TTL = 60;

    /** 原子"取值并删除"脚本，未命中返回 nil */
    protected const CONSUME_SCRIPT = "local v = redis.call('GET', KEYS[1]); if v then redis.call('DEL', KEYS[1]); return v; end; return nil;";

    /**
     * 为登录主体签发一次性免登 ticket。
     *
     * @param string $sid   登录主体 sid（人）
     * @param string $appId 目标应用标识（绑定，消费时需一致）
     * @param string $entry 入口类型：entry(普通使用) / manage_entry(管理入口)，仅作上下文透传
     * @param int|null $ttl 有效期（秒），默认取缓存域配置或 60s
     * @return string 签名后的 ticket（base64url(payload).base64url(hmac)）
     */
    public static function issue(string $sid, string $appId, string $entry = 'entry', ?int $ttl = null): string
    {
        if ($sid === '' || $appId === '') {
            throw new ApiException('签发免登票据参数不完整');
        }
        $ttl = $ttl ?? self::ttl();
        $now = time();
        $jti = bin2hex(random_bytes(16));

        $payload = [
            'sid' => $sid,
            'aud' => $appId,
            'entry' => $entry,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => $jti,
        ];

        $body = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sig = self::base64UrlEncode(hash_hmac('sha256', $body, self::secret(), true));
        $ticket = $body . '.' . $sig;

        // 登记 jti，供消费时原子核销（防重放）
        Redis::setex(self::jtiKey($jti), $ttl, $sid . '|' . $appId);

        return $ticket;
    }

    /**
     * 校验并原子消费 ticket，成功返回携带的 sid 等上下文，失败返回 null。
     *
     * @param string $ticket      待消费的 ticket
     * @param string|null $expectAppId 若提供，则校验 ticket 绑定的应用是否一致
     * @return array{sid:string, app:string, entry:string}|null
     */
    public static function consume(string $ticket, ?string $expectAppId = null): ?array
    {
        $parts = explode('.', $ticket);
        if (count($parts) !== 2) {
            return null;
        }
        [$body, $sig] = $parts;

        // 1) 验签（常数时间比较，防时序侧信道）
        $expect = self::base64UrlEncode(hash_hmac('sha256', $body, self::secret(), true));
        if (!hash_equals($expect, $sig)) {
            return null;
        }

        // 2) 解析并校验时效 / 结构
        $payload = json_decode(self::base64UrlDecode($body), true);
        // aud 必须存在：签发端从不产出空 aud 的票据，出现空 aud 只可能是构造出来的，
        // 而调用方正是靠 aud 判定"这张票该由哪个应用兑、要不要验 client_secret"
        if (!is_array($payload) || empty($payload['sid']) || empty($payload['jti']) || ($payload['aud'] ?? '') === '') {
            return null;
        }
        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }
        if ($expectAppId !== null && ($payload['aud'] ?? '') !== $expectAppId) {
            return null;
        }

        // 3) 原子核销 jti：取回并删除。未命中说明已被使用或从未签发（重放）
        $consumed = Redis::eval(self::CONSUME_SCRIPT, [self::jtiKey((string) $payload['jti'])], 1);
        if (empty($consumed)) {
            return null;
        }

        return [
            'sid' => (string) $payload['sid'],
            'app' => (string) ($payload['aud'] ?? ''),
            'entry' => (string) ($payload['entry'] ?? ''),
        ];
    }

    /**
     * 拼装带 ticket 的应用跳转地址。
     */
    public static function attachToUrl(string $url, string $ticket): string
    {
        if ($url === '') {
            return $url;
        }
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query(['ticket' => $ticket]);
    }

    /** 签名密钥（从 JWT/APP 密钥派生，独立 HKDF 上下文，密钥不外泄） */
    protected static function secret(): string
    {
        $seed = (string) env('JWT_ACCESS_KEY', '');
        if ($seed === '') {
            $seed = (string) env('APP_KEY', '');
        }
        if ($seed === '') {
            throw new ApiException('缺少免登签名密钥，请在 .env 中配置 JWT_ACCESS_KEY');
        }
        return hash_hkdf('sha256', $seed, 32, 'atrman-login-ticket');
    }

    /** 从缓存域配置读取票据时效 */
    protected static function ttl(): int
    {
        $cfg = CacheManager::config('ticket');
        $ttl = (int) ($cfg['expire'] ?? self::DEFAULT_TTL);
        return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
    }

    protected static function jtiKey(string $jti): string
    {
        return self::NONCE_PREFIX . $jti;
    }

    protected static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
