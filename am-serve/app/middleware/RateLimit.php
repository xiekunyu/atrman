<?php

namespace app\middleware;

use app\cache\ReflectionCache;
use app\service\auth\RateLimiter;
use app\utils\ClientInfo;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 框架级全局限流中间件（基座与插件路由同时生效）。
 *
 * 阈值来源（按优先级）：
 * 1. 方法级 #[RateLimit] 注解；2. 类级 #[RateLimit] 注解；
 * 3. 无注解的写方法（POST/PUT/DELETE/PATCH）套用 .env 默认阈值；GET 默认不限。
 * 注解 max=0 显式跳过（captcha/login 等已有专属频控的端点不重复计数）。
 *
 * 位置在 SystemMaintenance 之后（维护页优先）、AuthCheck 之前（未登录洪水在
 * JWT 解码前就被 429 挡掉）。Redis 故障由 RateLimiter fail-open 并记 warning。
 */
class RateLimit implements MiddlewareInterface
{
    /** 套用默认阈值的写方法 */
    private const WRITE_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    public function process(Request $request, callable $handler): Response
    {
        // 总开关：关闭时纯透传，无限流副作用
        if (!filter_var(env('RATELIMIT_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
            return $handler($request);
        }

        $method = strtoupper($request->method());
        // CORS 预检不产生业务调用，直接放行
        if ($method === 'OPTIONS') {
            return $handler($request);
        }

        $path = '/' . ltrim($request->path(), '/');
        // 健康探针（liveness/readiness）不参与限流，避免编排探针被 429 误判实例异常
        if ($path === '/health' || str_starts_with($path, '/health/')) {
            return $handler($request);
        }

        // 静态资源/回退等无控制器调度的请求不限流
        if (empty($request->controller) || empty($request->action)) {
            return $handler($request);
        }

        $rule = $this->resolveRule($request, $method);
        if ($rule === null) {
            return $handler($request);
        }

        $scene = $rule['scene'] !== ''
            ? $rule['scene']
            : $request->controller . '::' . $request->action;
        $identity = $this->resolveIdentity($request, $rule['scope']);
        if ($identity === '') {
            return $handler($request);
        }

        $result = RateLimiter::check($scene, $identity, $rule['max'], $rule['window']);
        if ($result['limited']) {
            // 不抛异常、不走后续业务链，直接返 429 并带 Retry-After
            return warning(
                '请求过于频繁，请 ' . $result['retry_after'] . ' 秒后再试',
                '',
                0,
                1,
                429
            )->withHeader('Retry-After', (string) $result['retry_after']);
        }

        return $handler($request);
    }

    /**
     * 解析本次请求适用的限流规则；返回 null 表示不限流。
     *
     * @return array{max: int, window: int, scope: string, scene: string}|null
     */
    private function resolveRule(Request $request, string $method): ?array
    {
        $attribute = ReflectionCache::getRateLimitAttributes($request->controller, $request->action);
        if (!empty($attribute)) {
            // 显式 max=0：跳过（端点已有专属频控）
            if ((int) $attribute['max'] <= 0) {
                return null;
            }
            return [
                'max' => (int) $attribute['max'],
                'window' => (int) $attribute['window'] > 0 ? (int) $attribute['window'] : 60,
                'scope' => (string) $attribute['scope'],
                'scene' => (string) $attribute['scene'],
            ];
        }

        // 无注解：仅写方法套用 .env 默认阈值，GET 等只读请求不限
        if (!in_array($method, self::WRITE_METHODS, true)) {
            return null;
        }
        $max = (int) env('RATELIMIT_WRITE_MAX', 120);
        if ($max <= 0) {
            return null;
        }
        return [
            'max' => $max,
            'window' => (int) env('RATELIMIT_WRITE_WINDOW', 60),
            'scope' => 'ip_uid',
            'scene' => '',
        ];
    }

    /**
     * 按 scope 解析限流身份：uid 维度带 uid: 前缀、IP 维度带 ip: 前缀，避免键空间碰撞。
     */
    private function resolveIdentity(Request $request, string $scope): string
    {
        $uid = currentUserId();
        if ($scope === 'ip') {
            return 'ip:' . ClientInfo::clientIp($request);
        }
        // scope=uid 与默认 ip_uid 同口径：登录后按 uid，未登录回退 IP
        if ($uid > 0) {
            return 'uid:' . $uid;
        }
        $ip = ClientInfo::clientIp($request);
        return $ip !== '' ? 'ip:' . $ip : '';
    }
}
