<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\utils\ServerMonitor;
use app\service\Permission;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Response;

#[RouteGroup('/sys/server')]
class ServerController extends BaseController
{
    #[Get('/monitor')]
    #[Permission(title: '服务器监控', slug: 'system:server:monitor')]
    public function monitor(): Response
    {
        return success('', ServerMonitor::monitor());
    }
}
