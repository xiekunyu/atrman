<?php

namespace app\controller\notify;

use app\basic\BaseController;
use app\model\notify\NotifyPreference;
use app\service\NotifyService;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/notify/preference')]
class NotifyPreferenceController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new NotifyPreference();
    }

    #[Get('/index')]
    #[Permission(title: '通知偏好列表', slug: 'notify:preference:index')]
    public function index(Request $request): Response
    {
        $userInfo = getCurrentInfo();
        $userId = $userInfo['id'] ?? 0;

        $list = $this->model->where('user_id', $userId)
            ->order('type asc, notify_type asc')
            ->select()
            ->toArray();

        return success('', $list);
    }

    #[Post('/save')]
    #[Permission(title: '保存通知偏好', slug: 'notify:preference:save')]
    public function save(Request $request): Response
    {
        $userInfo = getCurrentInfo();
        $userId = $userInfo['id'] ?? 0;

        $type = $request->post('type', '');
        $notifyType = $request->post('notify_type', '*');
        $enabled = $request->post('enabled', 1);

        // 通道白名单动态取注册表（内置 + 插件注册），插件通道"即注册即受偏好管理"
        if (empty($type) || !in_array($type, NotifyService::getRegisteredTypes())) {
            return warning('通知类型参数错误');
        }

        $pref = NotifyPreference::setPreference($userId, $type, $notifyType, (bool) $enabled);

        return success('保存成功', $pref);
    }

    #[Post('/batchSave')]
    #[Permission(title: '批量保存通知偏好', slug: 'notify:preference:batchSave')]
    public function batchSave(Request $request): Response
    {
        $userInfo = getCurrentInfo();
        $userId = $userInfo['id'] ?? 0;

        $items = $request->post('items', []);
        if (empty($items)) {
            return warning('参数错误');
        }

        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            $notifyType = $item['notify_type'] ?? '*';
            $enabled = $item['enabled'] ?? 1;

            if (empty($type) || !in_array($type, NotifyService::getRegisteredTypes())) {
                continue;
            }

            NotifyPreference::setPreference($userId, $type, $notifyType, (bool) $enabled);
        }

        return success('保存成功');
    }
}
