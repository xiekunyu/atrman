<?php

namespace app\controller\notify;

use app\basic\BaseController;
use app\model\notify\NotifyMessage;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/notify/inbox')]
class NotifyInboxController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new NotifyMessage();
    }

    #[Get('/index')]
    #[Permission(title: '站内信列表', slug: 'notify:inbox:index')]
    public function index(Request $request): Response
    {
        $userInfo = getCurrentInfo();
        $userId = $userInfo['id'] ?? 0;

        $where = $request->buildMap([
            ['title', ''],
            ['type', ''],
            ['is_read', ''],
        ]);

        $query = $this->search($this->model, $where)
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->order('create_time desc');

        $list = $this->getList($query);

        // 转为数组并附加未读数量，避免 returnListData 双重包装
        $result = is_array($list) ? $list : $list->toArray();
        $payload = [
            'items' => $result['data'] ?? $result,
            'total' => $result['total'] ?? (is_countable($result) ? count($result) : 0),
            'unread_count' => NotifyMessage::getUnreadCount($userId),
        ];

        return success('', $payload);
    }

    #[Get('/read')]
    #[Permission(title: '站内信详情', slug: 'notify:inbox:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        $userInfo = getCurrentInfo();
        $userId = $userInfo['id'] ?? 0;

        if (empty($id)) {
            return warning('参数错误');
        }

        $message = $this->model->where('id', $id)
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->find();

        if (!$message) {
            return warning('消息不存在');
        }

        // 自动标记为已读
        if (!$message->is_read) {
            $message->is_read = 1;
            $message->read_at = date('Y-m-d H:i:s');
            $message->save();
        }

        return success('', $message);
    }

    #[Post('/markRead')]
    #[Permission(title: '标记已读', slug: 'notify:inbox:markRead')]
    public function markRead(Request $request): Response
    {
        $id = $request->post('id');
        $userInfo = getCurrentInfo();
        $userId = $userInfo['id'] ?? 0;

        if (empty($id)) {
            return warning('参数错误');
        }

        $message = $this->model->where('id', $id)
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->find();

        if (!$message) {
            return warning('消息不存在');
        }

        $message->is_read = 1;
        $message->read_at = date('Y-m-d H:i:s');
        $message->save();

        return success('标记已读成功');
    }

    #[Post('/markAllRead')]
    #[Permission(title: '全部标记已读', slug: 'notify:inbox:markAllRead')]
    public function markAllRead(Request $request): Response
    {
        $userInfo = getCurrentInfo();
        $userId = $userInfo['id'] ?? 0;

        $count = NotifyMessage::markAllAsRead($userId);

        return success('全部标记已读成功', ['count' => $count]);
    }

    #[Post('/delete')]
    #[Permission(title: '删除站内信', slug: 'notify:inbox:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        $userInfo = getCurrentInfo();
        $userId = $userInfo['id'] ?? 0;

        if (empty($id)) {
            return warning('参数错误');
        }

        $message = $this->model->where('id', $id)
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->find();

        if (!$message) {
            return warning('消息不存在');
        }

        // 用户侧删除（软删除）
        $message->is_deleted = 1;
        $message->save();

        return success('删除成功');
    }

    #[Post('/batchDelete')]
    #[Permission(title: '批量删除站内信', slug: 'notify:inbox:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        $userInfo = getCurrentInfo();
        $userId = $userInfo['id'] ?? 0;

        if (empty($ids)) {
            return warning('参数错误');
        }

        $count = $this->model->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->whereIn('id', $ids)
            ->update(['is_deleted' => 1]);

        return success('删除成功', ['count' => $count]);
    }

    #[Get('/unreadCount')]
    #[Permission(title: '获取未读数量', slug: 'notify:inbox:unreadCount')]
    public function unreadCount(Request $request): Response
    {
        $userInfo = getCurrentInfo();
        $userId = $userInfo['id'] ?? 0;

        $count = NotifyMessage::getUnreadCount($userId);

        return success('', ['count' => $count]);
    }
}
