<?php

namespace app\model\notify;

use app\basic\BaseModel;

class NotifyMessage extends BaseModel
{
    protected $name = 'system_notify_message';

    protected $json = ['extra'];

    // 不使用软删除（用 is_deleted 字段做用户侧删除）
    protected $deleteTime = false;

    // 搜索器：标题搜索
    public function searchTitleAttr($query, $value)
    {
        if ($value) {
            $query->where('title', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    // 搜索器：消息分类筛选
    public function searchTypeAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('type', $value);
        }
    }

    // 搜索器：已读状态筛选
    public function searchIsReadAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('is_read', $value);
        }
    }

    // 搜索器：用户ID筛选
    public function searchUserIdAttr($query, $value)
    {
        if ($value) {
            $query->where('user_id', $value);
        }
    }

    /**
     * 获取用户未读消息数量
     */
    public static function getUnreadCount(int $userId): int
    {
        return static::where('user_id', $userId)
            ->where('is_read', 0)
            ->where('is_deleted', 0)
            ->count();
    }

    /**
     * 标记用户所有消息为已读
     */
    public static function markAllAsRead(int $userId): int
    {
        return static::where('user_id', $userId)
            ->where('is_read', 0)
            ->update([
                'is_read' => 1,
                'read_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
