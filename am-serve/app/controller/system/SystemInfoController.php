<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\service\system\ObservabilityService;
use app\attribute\Permission;
use app\service\system\SystemInfoService;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Response;

/**
 * 系统信息：主程序自身信息展示（版本/环境/组件/插件统计/更新角标）。
 * 与"服务器监控"（ServerController，资源指标）职责区分；全量脱敏，见 SystemInfoService。
 */
#[RouteGroup('/sys/systemInfo')]
class SystemInfoController extends BaseController
{
    #[Get('/index')]
    #[Permission(title: '系统信息', slug: 'system:systemInfo:index')]
    public function index(): Response
    {
        return success('', SystemInfoService::index());
    }

    /**
     * 可观测性面板数据（队列积压 + 前端错误聚合）。
     * 复用系统信息页权限 slug：受众同为后台管理员，避免新增无人分配的孤立权限项。
     */
    #[Get('/observability')]
    #[Permission(title: '系统信息', slug: 'system:systemInfo:index')]
    public function observability(): Response
    {
        return success('', ObservabilityService::index());
    }
}
