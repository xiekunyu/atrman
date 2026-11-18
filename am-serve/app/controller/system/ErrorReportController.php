<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\service\system\ErrorReportService;
use app\service\auth\LoginThrottleService;
use app\utils\ClientInfo;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

/**
 * 前端错误上报。
 *
 * report 是公开动作（门户/登录页等匿名场景也要能上报），靠 IP 频控防刷、
 * 服务端白名单+脱敏防脏数据。明细表只存不回显：不提供详情/列表查询接口，
 * 聚合计数统一由 SystemInfoController::observability 输出（复用系统信息权限点）。
 */
#[RouteGroup('/sys/errorReport')]
class ErrorReportController extends BaseController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['report'];

    /**
     * 前端错误投递：恒返回 success，存储/校验失败不向前端冒泡（避免上报回路放大）。
     */
    #[Post('/report')]
    public function report(Request $request): Response
    {
        // 公开端点频控：60s 内每 IP 最多 30 次，Redis 故障 fail-open
        LoginThrottleService::assertIpRateLimited(
            'error-report',
            ClientInfo::clientIp($request),
            30,
            60
        );

        $payload = [
            'app' => (string)$request->post('app', ''),
            'level' => (string)$request->post('level', 'error'),
            'type' => (string)$request->post('type', ''),
            'message' => (string)$request->post('message', ''),
            'stack' => (string)$request->post('stack', ''),
            'url' => (string)$request->post('url', ''),
            'user_agent' => (string)$request->header('user-agent', ''),
        ];

        $uid = currentUserId();
        ErrorReportService::report($payload, ClientInfo::clientIp($request), $uid > 0 ? $uid : null);

        return success('上报成功');
    }
}
