<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\model\system\UserLoginLog;
use app\service\Permission;
use support\annotation\route\Get;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

#[RouteGroup('/sys/loginLog')]
class LoginLogController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new UserLoginLog();
    }

    #[Get('/index')]
    #[Permission(title: '登录日志列表', slug: 'system:loginLog:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['username', ''],
            ['ip', ''],
            ['status', ''],
            ['keywords', ''],
            ['create_time', ''],
        ]);

        return $this->returnListData($this->getList($this->search($this->model, $where)));
    }

    #[Get('/read')]
    #[Permission(title: '登录日志详情', slug: 'system:loginLog:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('登录日志不存在');
        }

        return success('', $info);
    }
}
