<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\cache\CacheManager;
use app\service\Permission;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

#[RouteGroup('/sys/cache')]
class CacheController extends BaseController
{
    #[Get('/monitor')]
    #[Permission(title: '缓存监控', slug: 'system:cache:index')]
    public function monitor(): Response
    {
        return success('', CacheManager::monitor());
    }

    #[Get('/index')]
    #[Permission(title: '缓存列表', slug: 'system:cache:index')]
    public function index(): Response
    {
        return $this->monitor();
    }

    #[Post('/clear')]
    #[Permission(title: '清理缓存', slug: 'system:cache:clear')]
    public function clear(Request $request): Response
    {
        $code = (string) $request->post('code', '');
        $result = CacheManager::clearNamespace($code);
        if (($result['success'] ?? true) === false) {
            return warning($result['message'] ?? '清理失败', $result);
        }
        return success('清理成功', $result);
    }
}
