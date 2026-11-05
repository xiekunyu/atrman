<?php

namespace app\model\oauth;

use app\basic\BaseModel;

/**
 * OAuth 访问令牌模型
 */
class AccessToken extends BaseModel
{
    protected $name = 'oauth_access_token';
    
    /**
     * 创建访问令牌（含 refresh_token）
     */
    public static function createToken(string $clientId, string $sid, string $scopes = '', int $expiresIn = 7200): array
    {
        $token = bin2hex(random_bytes(32));
        $refreshToken = bin2hex(random_bytes(32));
        $expiresAt = time() + $expiresIn;
        // refresh_token 有效期为 access_token 的 2 倍
        $refreshExpiresAt = time() + ($expiresIn * 2);
        
        static::create([
            'token' => $token,
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'sid' => $sid,
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
            'refresh_expires_at' => $refreshExpiresAt,
        ]);
        
        return [
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'scope' => $scopes,
        ];
    }
    
    /**
     * 使用 refresh_token 刷新访问令牌
     * 
     * 旧令牌作废，生成新的 access_token + refresh_token
     */
    public static function refreshAccessToken(string $refreshToken, string $clientId): ?array
    {
        $record = static::where('refresh_token', $refreshToken)
            ->where('client_id', $clientId)
            ->where('refresh_expires_at', '>', time())
            ->find();
        
        if (!$record) {
            return null;
        }
        
        // 撤销旧令牌
        $sid = $record->sid;
        $scopes = $record->scopes;
        $record->delete();
        
        // 生成新令牌
        return static::createToken($clientId, $sid, $scopes);
    }
    
    /**
     * 验证访问令牌
     */
    public static function validateToken(string $token): ?self
    {
        $record = static::where('token', $token)
            ->where('expires_at', '>', time())
            ->find();
        
        return $record ?: null;
    }
    
    /**
     * 撤销访问令牌
     *
     * @param string $ownerSid 传入时只撤销该主体名下的令牌，避免任意登录账号撤销他人令牌
     */
    public static function revokeToken(string $token, string $ownerSid = ''): bool
    {
        $query = static::where('token', $token);
        if ($ownerSid !== '') {
            $query->where('sid', $ownerSid);
        }
        return $query->delete() > 0;
    }
    
    /**
     * 撤销某个主体（sid）的所有令牌
     */
    public static function revokeSubjectTokens(string $sid, ?string $clientId = null): int
    {
        $query = static::where('sid', $sid);
        if ($clientId) {
            $query->where('client_id', $clientId);
        }
        return $query->delete();
    }
    
    /**
     * 清理过期令牌
     */
    public static function clearExpired(): int
    {
        return static::where('expires_at', '<', time())->delete();
    }
}
