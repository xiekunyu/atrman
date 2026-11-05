<?php
namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 安全响应头。
 *
 * 只下发"对所有场景都成立"的三项；CSP 需要按前端资源（内联脚本、CDN、iframe 嵌三方应用）
 * 的实际分布单独设计，不适合在这里一次性上，故未下发。
 */
class SecurityHeaders implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $response = $handler($request);

        // 安装页与后台都是 HTML，被外框嵌走就意味着凭证在别人页面里可用
        $response->withHeaders([
            // 不让自己被跨源 frame（插件应用与基座同源，/app/{name} 嵌入不受影响）
            'X-Frame-Options' => 'SAMEORIGIN',
            // 杜绝浏览器按内容嗅探类型（上传的 .txt 被当脚本执行一类）
            'X-Content-Type-Options' => 'nosniff',
            // 跳外站时只带 origin，不带完整路径（避免会话标识之类落在 Referer 里）
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);

        return $response;
    }
}
