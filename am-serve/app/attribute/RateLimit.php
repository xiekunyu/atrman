<?php

namespace app\attribute;

/**
 * 限流注解（方法级优先，其次类级；由 app\middleware\RateLimit 统一执行）。
 *
 * 用法：#[RateLimit(max: 20, window: 60, scope: 'ip_uid')]
 * - max：窗口内最大请求数；显式写 0 表示跳过限流（如已有专属频控的 captcha/login）
 * - window：计数窗口（秒），固定窗口口径
 * - scope：ip=仅按客户端 IP；uid=仅按登录用户（未登录回退 IP）；
 *          ip_uid=登录后按 uid、未登录回退 IP（默认）
 * - scene：Redis 键名中的场景标识，留空默认取 控制器::动作
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
class RateLimit
{
    public function __construct(
        public readonly int $max,
        public readonly int $window = 60,
        public readonly string $scope = 'ip_uid',
        public readonly string $scene = '',
    ) {
    }
}
