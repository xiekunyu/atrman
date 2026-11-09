<?php

namespace app\middleware;

use app\controller\InstallController;
use app\service\InstallService;
use Webman\MiddlewareInterface;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * 安装守卫中间件
 *
 * 安装态由 InstallService::isInstalled() 统一判定（双重锁：文件锁 + APP_INSTALL，
 * 并在文件锁丢失时回源查库兜底，防止锁消失后重装接管）。
 *
 * 未安装时拦截所有非安装请求，引导用户进入安装向导；安装路由直接调度到 InstallController，
 * 跳过后续中间件（AuthCheck 等）。已安装时反向封死安装路由——AuthCheck 对 /api/install/
 * 前缀免登录，不在这里拦就会永久匿名可达。
 */
class InstallGuard implements MiddlewareInterface
{
    /**
     * 安装路由映射：路径后缀 => [控制器方法]
     */
    private const INSTALL_ROUTES = [
        '/api/install/status'      => ['status', 'GET'],
        '/api/install/license'     => ['license', 'GET'],
        '/api/install/agree'       => ['agree', 'POST'],
        '/api/install/environment' => ['environment', 'GET'],
        '/api/install/testDatabase'=> ['testDatabase', 'POST'],
        '/api/install/testRedis'   => ['testRedis', 'POST'],
        '/api/install/checkDatabase'=> ['checkDatabase', 'POST'],
        '/api/install/execute'     => ['execute', 'POST'],
    ];

    public function process(Request $request, callable $handler): Response
    {
        $path = '/' . ltrim($request->path(), '/');

        // 安装态判定统一收口到 InstallService（双重锁 + 数据兜底），避免两处口径漂移
        if (InstallService::isInstalled()) {
            // 已安装：安装路由不得再被调度（AuthCheck 对该前缀免登录，必须在这里封死）
            if (isset(self::INSTALL_ROUTES[$path])) {
                return json(['code' => 404, 'msg' => '系统已安装', 'data' => null]);
            }
            return $handler($request);
        }

        // 未安装：检查是否为安装路由
        $method = strtoupper($request->method());

        if (isset(self::INSTALL_ROUTES[$path])) {
            [$action, $allowedMethod] = self::INSTALL_ROUTES[$path];
            if ($method !== $allowedMethod) {
                return json(['code' => 405, 'msg' => '请求方法不允许', 'data' => null]);
            }
            // 直接调用 InstallController，跳过后续中间件（AuthCheck 等）
            $controller = new InstallController();
            return $controller->$action($request);
        }

        // 未安装：放行静态安装页面
        if ($path === '/install.html') {
            return $handler($request);
        }

        // 未安装：浏览器请求重定向到安装页
        $accept = $request->header('accept', '');
        if (str_contains($accept, 'text/html')) {
            return redirect('/install.html');
        }

        // 未安装：其他 API 返回 503
        return json(['code' => 503, 'msg' => '系统尚未安装，请先完成安装', 'data' => null]);
    }
}
