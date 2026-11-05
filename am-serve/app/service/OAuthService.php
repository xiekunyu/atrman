<?php

namespace app\service;

use app\cache\CacheManager;
use app\model\oauth\AccessToken;
use app\model\oauth\AuthCode;
use app\model\plugin\App as Plugin;
use app\model\system\User;
use support\Redis;

/**
 * OAuth 授权服务
 * 
 * 实现 OAuth 2.0 授权码流程，支持三方应用获取本系统权限
 */
class OAuthService
{
    /** SSO 凭证 Redis 键前缀（与 cache_manage.oauth.prefix 默认值一致） */
    protected const SSO_PREFIX = 'atrman:oauth:sso_';

    /** 原子"取值并删除"脚本，未命中返回 nil（防重放，参照 TicketService） */
    protected const CONSUME_SCRIPT = "local v = redis.call('GET', KEYS[1]); if v then redis.call('DEL', KEYS[1]); return v; end; return nil;";

    /**
     * 创建授权码
     * 
     * @param string $clientId 客户端ID
     * @param string $sid 全局主体唯一ID（人）
     * @param string $redirectUri 回调地址
     * @param string $scopes 授权范围
     * @return string 授权码
     */
    public static function createAuthCode(string $clientId, string $sid, string $redirectUri, string $scopes = ''): string
    {
        return AuthCode::createCode($clientId, $sid, $redirectUri, $scopes);
    }
    
    /**
     * 验证客户端凭证
     * 
     * @param string $clientId 客户端ID
     * @param string $clientSecret 客户端密钥
     * @return Plugin|null 验证通过返回插件实例，失败返回null
     */
    private static function validateClient(string $clientId, string $clientSecret): ?Plugin
    {
        $plugin = Plugin::where('oauth_client_id', $clientId)
            ->where('type', 3)
            ->where('status', 1)
            ->find();
        
        if (!$plugin || !hash_equals((string) $plugin->oauth_client_secret, $clientSecret)) {
            return null;
        }
        
        return $plugin;
    }
    
    /**
     * 用授权码换取访问令牌
     * 
     * @param string $code 授权码
     * @param string $clientId 客户端ID
     * @param string $clientSecret 客户端密钥
     * @return array|null 令牌数据或null
     */
    public static function exchangeToken(string $code, string $clientId, string $clientSecret): ?array
    {
        // 验证客户端
        $plugin = self::validateClient($clientId, $clientSecret);
        if (!$plugin) {
            return null;
        }
        
        // 验证授权码
        $authCode = AuthCode::validateCode($code, $clientId);
        if (!$authCode) {
            return null;
        }
        
        // 原子核销授权码（一次性使用）：条件删除影响行数为 0 说明已被并发请求消费
        $claimed = AuthCode::where('id', $authCode->id)->delete();
        if (!$claimed) {
            return null;
        }
        
        // 创建访问令牌
        return AccessToken::createToken($clientId, $authCode->sid, $authCode->scopes);
    }
    
    /**
     * 使用 refresh_token 刷新访问令牌
     * 
     * @param string $refreshToken 刷新令牌
     * @param string $clientId 客户端ID
     * @param string $clientSecret 客户端密钥
     * @return array|null 新令牌数据或null
     */
    public static function refreshAccessToken(string $refreshToken, string $clientId, string $clientSecret): ?array
    {
        // 验证客户端
        $plugin = self::validateClient($clientId, $clientSecret);
        if (!$plugin) {
            return null;
        }
        
        return AccessToken::refreshAccessToken($refreshToken, $clientId);
    }
    
    /**
     * 验证访问令牌
     * 
     * @param string $token 访问令牌
     * @return array|null 令牌信息或null
     */
    public static function validateToken(string $token): ?array
    {
        $record = AccessToken::validateToken($token);
        if (!$record) {
            return null;
        }
        
        return [
            'client_id' => $record->client_id,
            'sid' => $record->sid,
            'scopes' => $record->scopes,
            'expires_at' => $record->expires_at,
        ];
    }
    
    /**
     * 撤销访问令牌
     * 
     * @param string $token 访问令牌
     * @param string $ownerSid 撤销者主体：非空时只能撤销自己名下的令牌（超管传空串表示不限制）
     * @return bool
     */
    public static function revokeToken(string $token, string $ownerSid = ''): bool
    {
        return AccessToken::revokeToken($token, $ownerSid);
    }
    
    /**
     * 生成 SSO 免登凭证
     * 
     * @param string $sid 全局主体唯一ID（人）
     * @param string $clientId 客户端ID
     * @return string SSO凭证
     */
    public static function createSSOToken(string $sid, string $clientId): string
    {
        $token = bin2hex(random_bytes(32));
        $cacheConfig = CacheManager::config('oauth');
        $ttl = (int)($cacheConfig['expire'] ?? 300);
        if ($ttl <= 0) {
            $ttl = 300;
        }

        // 直接存 Redis（默认缓存驱动即 redis），与 validateSSOToken 的 Lua 原子核销配套
        Redis::setex(self::ssoKey($token), $ttl, json_encode([
            'sid' => $sid,
            'client_id' => $clientId,
            'expires_at' => time() + $ttl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $token;
    }
    
    /**
     * 验证 SSO 免登凭证
     * 
     * @param string $token SSO凭证
     * @return array|null 凭证信息或null
     */
    public static function validateSSOToken(string $token): ?array
    {
        // 原子核销：取回并删除，未命中说明已被使用或从未签发（防重放）
        $raw = Redis::eval(self::CONSUME_SCRIPT, [self::ssoKey($token)], 1);
        if (empty($raw)) {
            return null;
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data) || ($data['expires_at'] ?? 0) < time()) {
            return null;
        }

        return $data;
    }

    /** SSO 凭证 Redis 键 */
    protected static function ssoKey(string $token): string
    {
        $cacheConfig = CacheManager::config('oauth');
        $prefix = (string)($cacheConfig['prefix'] ?? self::SSO_PREFIX);
        return $prefix . $token;
    }
    
    /**
     * 获取用户信息（用于 OAuth userinfo 接口）
     * 
     * @param string $sid 全局主体唯一ID（人）
     * @param string $scopes 授权范围
     * @return array|null
     */
    public static function getUserInfo(string $sid, string $scopes = ''): ?array
    {
        $user = User::where('sid', $sid)->find();
        if (!$user) {
            return null;
        }
        
        // 根据 scopes 返回不同字段
        $scopeList = explode(',', $scopes);
        $info = [
            'sid' => $user->sid,
            'username' => $user->username,
        ];
        
        if (in_array('profile', $scopeList) || in_array('basic', $scopeList) || empty($scopes)) {
            $info['nickname'] = $user->nickname;
            $info['avatar'] = $user->avatar;
        }
        
        if (in_array('email', $scopeList)) {
            $info['email'] = $user->email;
        }
        
        if (in_array('mobile', $scopeList)) {
            $info['mobile'] = $user->mobile;
        }
        
        if (in_array('dept', $scopeList)) {
            // 获取部门信息
            $dept = $user->mainDept;
            if ($dept) {
                $info['dept_id'] = $dept->id;
                $info['dept_name'] = $dept->name;
            }
        }
        
        return $info;
    }
    
    /**
     * 清理过期数据
     */
    public static function clearExpired(): void
    {
        AuthCode::clearExpired();
        AccessToken::clearExpired();
    }
}
