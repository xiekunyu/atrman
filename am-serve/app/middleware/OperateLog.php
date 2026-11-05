<?php

namespace app\middleware;

use app\cache\ReflectionCache;
use Webman\Event\Event;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class OperateLog implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $shouldLog = $this->shouldLog($request);
        $response = $handler($request);

        if ($shouldLog) {
            Event::emit('user.operateLog', $response);
        }

        return $response;
    }

    /**
     * 需要被记录的操作日志
     */
    private function shouldLog(Request $request): bool
    {
        // get请求不记录
        if (strtoupper($request->method()) === 'GET') {
            return false;
        }

        if (empty($request->controller) || empty($request->action)) {
            return false;
        }

        $path = '/' . ltrim($request->path(), '/');
        if (str_starts_with($path, '/pub/')) {
            return false;
        }

        if (!class_exists($request->controller) || !method_exists($request->controller, $request->action)) {
            return false;
        }

        $noNeedLogin = ReflectionCache::getNoNeedLogin($request->controller);

        return !in_array($request->action, $noNeedLogin, true);
    }
}
