<?php

namespace app\basic;

use app\exception\ApiException;
use support\Log;

/**
 * JWT 密钥装配检查。
 *
 * tinywan/jwt 的配置把 access/refresh 秘钥直接取 env()，缺省即 null；
 * HS* 算法下用空串秘钥签出的令牌能被空串秘钥验通过——.env 少写一行就等于任何人都能伪造任意身份，
 * 包括超管。所以验签与签发前必须先确认密钥真的存在，宁可报错也不要静默降级成空密钥。
 */
class JwtKeyGuard
{
    /** 进程内 memo：webman 常驻，配置一次装配就固定下来，不必每请求重算 */
    private static ?bool $ok = null;

    /** 密钥缺失时抛 ApiException；已确认通过则直接命中 memo。 */
    public static function assertConfigured(): void
    {
        if (self::$ok === true) {
            return;
        }
        $config = (array)config('plugin.tinywan.jwt.app.jwt', []);
        // 只校 HS*（本项目的出厂算法）：RS*/ES* 走 PEM 私钥字段，不由这两项承担
        if (in_array((string)($config['algorithms'] ?? ''), ['HS256', 'HS384', 'HS512'], true)) {
            $missing = [];
            foreach (['access_secret_key' => 'JWT_ACCESS_KEY', 'refresh_secret_key' => 'JWT_REFRESH_KEY'] as $key => $envName) {
                if (trim((string)($config[$key] ?? '')) === '') {
                    $missing[] = $envName;
                }
            }
            if ($missing !== []) {
                // 每请求都记：命中说明配置的某一行被删掉了，只靠响应体容易被忽略
                Log::error('[JwtKeyGuard] JWT 密钥未配置：' . implode('、', $missing));
                throw new ApiException('JWT 密钥未配置（' . implode('、', $missing) . '），请检查 .env');
            }
        }
        self::$ok = true;
    }
}
