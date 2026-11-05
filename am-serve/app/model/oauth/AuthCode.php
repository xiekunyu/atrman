<?php

namespace app\model\oauth;

use app\basic\BaseModel;

/**
 * OAuth 授权码模型
 */
class AuthCode extends BaseModel
{
    protected $name = 'oauth_auth_code';
    
    /**
     * 创建授权码
     */
    public static function createCode(string $clientId, string $sid, string $redirectUri, string $scopes = ''): string
    {
        $code = bin2hex(random_bytes(32));
        $expiresAt = time() + 600; // 10分钟有效
        
        static::create([
            'code' => $code,
            'client_id' => $clientId,
            'sid' => $sid,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ]);
        
        return $code;
    }
    
    /**
     * 验证并获取授权码
     */
    public static function validateCode(string $code, string $clientId): ?self
    {
        $record = static::where('code', $code)
            ->where('client_id', $clientId)
            ->where('expires_at', '>', time())
            ->find();
        
        return $record ?: null;
    }
    
    /**
     * 清理过期授权码
     */
    public static function clearExpired(): int
    {
        return static::where('expires_at', '<', time())->delete();
    }
}
