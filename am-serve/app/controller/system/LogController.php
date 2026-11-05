<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\model\system\SystemLog;
use app\service\Permission;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

#[RouteGroup('/sys/log')]
class LogController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new SystemLog();
    }

    #[Get('/index')]
    #[Permission(title: '操作日志列表', slug: 'system:log:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['username', ''],
            ['method', ''],
            ['business_name', ''],
            ['router', ''],
            ['ip', ''],
            ['keywords', ''],
            ['create_time', ''],
        ]);

        return $this->returnListData($this->getList($this->search($this->model, $where)));
    }

    #[Get('/read')]
    #[Permission(title: '操作日志详情', slug: 'system:log:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->with(['user'=>function ($query) {
            $query->field('id', 'username', 'nickname', 'avatar');
        }])->findOrEmpty($id);
        if (!$info) {
            return warning('操作日志不存在');
        }

        return success('', $info);
    }
}
