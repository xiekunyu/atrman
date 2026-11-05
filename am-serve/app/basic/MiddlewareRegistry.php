<?php

namespace app\basic;

/**
 * 插件全局中间件注册中心
 *
 * 维护按优先级排序的 before/after 中间件链，由 app\middleware\PluginMiddleware
 * 在请求生命周期的入口/出口依次调度。安全敏感（插件代码直接织入主请求链），
 * 默认关闭；受信插件场景通过 config/plugin_extend.php 的 middleware_enabled 灰度开启。
 *
 * 进程级静态注册：插件 add() 一律写在 boot()，并传 owner=插件名。
 */
class MiddlewareRegistry
{
    /** @var list<array{type: string, middleware: callable, priority: int, owner: string, seq: int}> */
    private static array $middlewares = [];

    private static int $seq = 0;

    /**
     * 注册全局中间件
     *
     * @param string $when 织入时机：'before:request'（入口前置）或 'after:response'（出口后置）
     * @param callable $middleware
     *   before 签名：fn(Request $request): ?Response    // 返回 Response 则短路返回；返回 null 继续链
     *   after  签名：fn(Request $request, Response $response): ?Response  // 返回 null 保持原响应
     * @param int $priority 优先级，越大越先执行
     * @param string $owner 归属插件名（卸载/禁用对称清理用）
     */
    public static function add(string $when, callable $middleware, int $priority = 0, string $owner = ''): void
    {
        $type = match ($when) {
            'before:request' => 'before',
            'after:response' => 'after',
            default => null,
        };
        if ($type === null) {
            return; // 未知时机静默忽略，避免误调用污染链
        }
        self::$middlewares[] = [
            'type' => $type,
            'middleware' => $middleware,
            'priority' => $priority,
            'owner' => $owner,
            'seq' => self::$seq++,
        ];
    }

    /**
     * 获取某类中间件（按优先级降序、同优先级按注册先后）
     *
     * @return list<array{type: string, middleware: callable, priority: int, owner: string, seq: int}>
     */
    public static function all(string $type): array
    {
        if (!self::enabled()) {
            return [];
        }
        $list = array_values(array_filter(self::$middlewares, fn($m) => $m['type'] === $type));
        usort($list, fn($a, $b) => [$b['priority'], $a['seq']] <=> [$a['priority'], $b['seq']]);
        return $list;
    }

    /** 是否有任何注册（供诊断/冒烟检查） */
    public static function has(string $type): bool
    {
        return (bool)array_filter(self::$middlewares, fn($m) => $m['type'] === $type);
    }

    /** 按 owner 清理某插件的全部中间件 */
    public static function forgetByOwner(string $owner): void
    {
        if ($owner === '') {
            return;
        }
        self::$middlewares = array_values(array_filter(
            self::$middlewares,
            fn($m) => $m['owner'] !== $owner
        ));
    }

    /** 特性开关（默认关闭；非 webman 环境视为关闭，防止 CLI 误触发） */
    public static function enabled(): bool
    {
        return function_exists('config') ? (bool)config('plugin_extend.middleware_enabled', false) : false;
    }
}
