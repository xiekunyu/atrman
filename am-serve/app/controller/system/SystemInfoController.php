<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\service\Permission;
use app\service\SystemInfoService;
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
}
