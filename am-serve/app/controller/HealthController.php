<?php

namespace app\controller;

use app\service\system\HealthProbeService;
use support\annotation\route\Get;
use support\Response;

/**
 * 健康探针（无鉴权，AuthCheck 公开前缀白名单与 SystemMaintenance 白名单双重放行）
 *
 * - GET /health：liveness，进程能处理请求即 200，不查任何依赖——
 *   维护模式、Redis/DB 故障期间进程本身仍存活，探针不得误判实例死亡触发重启；
 * - GET /health/ready：readiness，Redis 与 DB 均可达才 200，任一不可用返回 503，
 *   供负载均衡 / 编排系统决定是否导流；依赖检查全部 try 兜底，探针自身永不抛异常。
 *
 * 探活实现统一收敛在 HealthProbeService，MetricsController 的延迟指标复用同一口径。
 *
 * 不走统一响应封装的 success()：探针消费方（Docker/K8s/监控）只认 HTTP 状态码，
 * body 保持极简 JSON 即可。
 */
class HealthController
{
    /**
     * 免登录方法（与 AuthCheck 前缀白名单互为兜底，任一口径调整都不致探针被鉴权拦截）
     */
    protected array $noNeedLogin = ['index', 'ready'];

    /** liveness：进程存活即 200 */
    #[Get('/health')]
    public function index(): Response
    {
        return json([
            'code' => 200,
            'msg' => 'ok',
            'data' => [
                'status' => 'up',
                'time' => time(),
            ],
        ]);
    }

    /** readiness：依赖逐项探活，任一 down 即 503 */
    #[Get('/health/ready')]
    public function ready(): Response
    {
        $probe = HealthProbeService::checks();
        $checks = [
            'redis' => $probe['redis']['status'],
            'db' => $probe['db']['status'],
        ];
        $ready = !in_array('down', $checks, true);

        return json([
            'code' => $ready ? 200 : 503,
            'msg' => $ready ? 'ready' : 'not ready',
            'data' => [
                'status' => $ready ? 'ready' : 'degraded',
                'checks' => $checks,
                'time' => time(),
            ],
        ], $ready ? 200 : 503);
    }
}
