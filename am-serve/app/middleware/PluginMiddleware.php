<?php

namespace app\middleware;

use app\basic\MiddlewareRegistry;
use support\Log;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 插件中间件调度器
 *
 * 固定入口挂在 config/middleware.php 全局链（无注册时纯透传，零影响）。
 * 读取 MiddlewareRegistry 的 before/after 链，按优先级依次调度：
 * - before 链：任何中间件返回 Response 则短路返回
 * - after  链：按优先级依次对响应做后处理，返回 null 保持原响应
 *
 * 单个中间件异常被 try/catch 隔离，记日志不阻断主流程（与基座中间件隔离原则一致）。
 */
class PluginMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (!MiddlewareRegistry::enabled()) {
            return $handler($request);
        }

        // before:request 链
        foreach (MiddlewareRegistry::all('before') as $entry) {
            try {
                $result = ($entry['middleware'])($request);
                if ($result instanceof Response) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::error(sprintf(
                    '[PluginMiddleware] before 中间件执行失败（owner=%s，priority=%d）：%s',
                    $entry['owner'] ?: '-',
                    $entry['priority'],
                    $e->getMessage()
                ));
            }
        }

        $response = $handler($request);

        // after:response 链
        foreach (MiddlewareRegistry::all('after') as $entry) {
            try {
                $result = ($entry['middleware'])($request, $response);
                if ($result instanceof Response) {
                    $response = $result;
                }
            } catch (\Throwable $e) {
                Log::error(sprintf(
                    '[PluginMiddleware] after 中间件执行失败（owner=%s，priority=%d）：%s',
                    $entry['owner'] ?: '-',
                    $entry['priority'],
                    $e->getMessage()
                ));
            }
        }

        return $response;
    }
}
