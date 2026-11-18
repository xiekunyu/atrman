<?php

namespace app\service\auth;

use app\basic\JwtKeyGuard;
use app\basic\JwtRevoker;
use app\exception\ApiException;
use app\model\system\User;
use app\utils\Str;
use tinywan\jwt\JwtToken;

/**
 * 令牌轮转服务：refresh_token 换发全新令牌对。
 *
 * 基座后台、am_member、am_store 共用同一份 tinywan/jwt 配置与 JwtRevoker 吊销名单，
 * 刷新口径收口在本类，避免各端各自验签导致语义漂移（历史问题：会员端刷新既不查吊销
 * 名单也不换 jti，登出后的 refresh 仍能换回新令牌）。
 *
 * 轮转语义：
 * 1. 用 refresh 密钥验签并校验有效期（JwtToken::verify type=2）；
 * 2. 旧 jti 必须存在且未吊销——refresh 端点在 noNeedLogin 白名单内，AuthCheck 不会代查；
 * 3. 回源确认账号仍存活（禁用/删号不能靠 refresh 续命）；
 * 4. 继承旧 extend 的身份字段，仅替换 jti 签发新对；
 * 5. 新对签发后旧 jti 写入吊销名单，旧 refresh 二次使用即被拒（检测点在第 2 步）。
 */
class TokenService
{
    /** tinywan/jwt 的 refresh 令牌类型常量（其类内为 private const，外部按原值引用） */
    private const REFRESH_TOKEN_TYPE = 2;

    /**
     * 用 refresh_token 轮转出新令牌对。
     *
     * @param string $refreshToken 可带 'Bearer ' 前缀
     * @return array{accessToken:string, refreshToken:string, user:array} 令牌值均为 'Bearer xxx' 完整头值
     * @throws ApiException 401：refresh 缺失/无效/过期/已吊销/账号已禁用
     */
    public static function rotate(string $refreshToken): array
    {
        $refreshToken = trim(str_replace('Bearer ', '', $refreshToken));
        if ($refreshToken === '') {
            throw new ApiException('刷新令牌无效，请重新登录', 401);
        }

        try {
            $decoded = JwtToken::verify(self::REFRESH_TOKEN_TYPE, $refreshToken);
        } catch (\Throwable $e) {
            throw new ApiException('登录态已过期，请重新登录', 401);
        }

        $extend = (array)($decoded['extend'] ?? []);
        $oldJti = (string)($extend['jti'] ?? '');
        $uid = (int)($extend['id'] ?? 0);
        if ($oldJti === '' || $uid <= 0) {
            // 无 jti 的老令牌不具备轮转资格：无法吊销旧对，放行等于永久续期
            throw new ApiException('刷新令牌无效，请重新登录', 401);
        }
        if (JwtRevoker::isRevoked($oldJti)) {
            // 命中登出/封禁/上一次轮转留下的吊销记录
            throw new ApiException('登录态已失效，请重新登录', 401);
        }

        $user = User::where('id', $uid)->where('status', 1)->find();
        if (!$user) {
            throw new ApiException('账号不存在或已被禁用', 401);
        }

        // 身份字段整体继承（scope/account_type/is_admin 等），仅替换会话标识实现双令牌轮转
        $newExtend = $extend;
        $newExtend['jti'] = Str::random(16);
        // 历史登录在 extend 内塞过自定义 exp（7200 硬编码），JWT 顶层 exp 才是权威值，不继承脏字段
        unset($newExtend['exp']);

        JwtKeyGuard::assertConfigured();
        $token = JwtToken::generateToken($newExtend);

        // 新对落袋后再吊销旧 jti。吊销写入失败仅 error 日志（JwtRevoker fail-open 口径），
        // 不阻断本次刷新：旧 refresh 的安全窗由其自身顶层 exp 兜底
        JwtRevoker::revoke($oldJti);

        return [
            'accessToken' => $token['token_type'] . ' ' . $token['access_token'],
            'refreshToken' => $token['token_type'] . ' ' . $token['refresh_token'],
            'user' => $newExtend,
        ];
    }
}
