<?php

namespace app\model\notify;

use app\basic\BaseModel;

class NotifyPreference extends BaseModel
{
    protected $name = 'system_notify_preference';

    // 不使用软删除
    protected $deleteTime = false;

    // 搜索器：用户ID筛选
    public function searchUserIdAttr($query, $value)
    {
        if ($value) {
            $query->where('user_id', $value);
        }
    }

    // 搜索器：通知类型筛选
    public function searchTypeAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('type', $value);
        }
    }

    /**
     * 检查用户是否接收指定类型和分类的通知
     */
    public static function isAccepted(int $userId, string $type, string $notifyType = '*'): bool
    {
        // 先查具体分类的偏好
        $pref = static::where('user_id', $userId)
            ->where('type', $type)
            ->where('notify_type', $notifyType)
            ->find();

        if ($pref) {
            return (bool) $pref->enabled;
        }

        // 再查全局偏好（notify_type='*'）
        if ($notifyType !== '*') {
            $globalPref = static::where('user_id', $userId)
                ->where('type', $type)
                ->where('notify_type', '*')
                ->find();

            if ($globalPref) {
                return (bool) $globalPref->enabled;
            }
        }

        // 默认接收
        return true;
    }

    /**
     * 设置用户偏好
     */
    public static function setPreference(int $userId, string $type, string $notifyType, bool $enabled): static
    {
        $pref = static::where('user_id', $userId)
            ->where('type', $type)
            ->where('notify_type', $notifyType)
            ->find();

        if ($pref) {
            // Query 批量 update 不触发模型自动时间戳，此处手动补 update_time
            static::where('id', $pref->id)->update([
                'enabled' => (int) $enabled,
                'update_time' => time(),
            ]);
            return $pref->refresh();
        }

        return static::create([
            'user_id' => $userId,
            'type' => $type,
            'notify_type' => $notifyType,
            'enabled' => (int) $enabled,
        ]);
    }
}
