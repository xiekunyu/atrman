<?php
namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class Cors implements MiddlewareInterface
{
    /** 本机来源：http(s)://localhost|127.0.0.1|::1，端口任意 */
    protected const LOCAL_PATTERN = '#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d{1,5})?$#i';

    public function process(Request $request, callable $handler): Response
    {
        // 1. 预检 OPTIONS 请求直接返回 204
        if ($request->method() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $handler($request);
        }

        $origin = (string) $request->header('origin', '');
        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
            // 是否放行取决于 Origin，缓存必须按来源分片
            'Vary' => 'Origin',
        ];

        // 2. 命中白名单才回显来源；未命中不带 Allow-Origin，浏览器自然拦截
        if ($origin !== '' && self::isAllowed($origin)) {
            // 携带凭证的跨域只能回具体来源，给 * 会被浏览器判为非法响应
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        $response->withHeaders($headers);

        return $response;
    }

    /**
     * 跨域来源判定，口径见 config/cors.php。
     */
    protected static function isAllowed(string $origin): bool
    {
        $config = (array) config('cors', []);
        if (in_array($origin, (array) ($config['allow_origins'] ?? []), true)) {
            return true;
        }
        if (!empty($config['allow_local']) && preg_match(self::LOCAL_PATTERN, $origin)) {
            return true;
        }

        // 开发态保持旧行为（任意来源放行），生产由 APP_DEBUG=false 关闭
        return (bool) config('app.debug', false);
    }
}
