<?php

namespace app\model\notify;

use app\basic\BaseModel;

class NotifyTemplate extends BaseModel
{
    protected $name = 'system_notify_template';

    protected $json = ['variables'];

    // 搜索器：关键词搜索
    public function searchKeywordsAttr($query, $value)
    {
        if ($value) {
            $query->where('name|code', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    // 搜索器：名称搜索
    public function searchNameAttr($query, $value)
    {
        if ($value) {
            $query->where('name', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    // 搜索器：编码搜索
    public function searchCodeAttr($query, $value)
    {
        if ($value) {
            $query->where('code', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    // 搜索器：来源筛选
    public function searchSourceAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('source', $value);
        }
    }

    // 搜索器：状态筛选
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', $value);
        }
    }

    /**
     * 根据编码获取模板
     */
    public static function getByCode(string $code): ?static
    {
        return static::where('code', $code)
            ->where('status', 1)
            ->find();
    }
}
