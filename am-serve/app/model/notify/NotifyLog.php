<?php

namespace app\model\notify;

use app\basic\BaseModel;

class NotifyLog extends BaseModel
{
    protected $name = 'system_notify_log';

    protected $json = ['channels'];

    // 不使用软删除（日志表保留所有记录）
    protected $deleteTime = false;

    // 搜索器：模板编码搜索
    public function searchTemplateCodeAttr($query, $value)
    {
        if ($value) {
            $query->where('template_code', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    // 搜索器：状态筛选
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', $value);
        }
    }

    // 搜索器：关键词搜索（标题/接收方/模板编码）
    public function searchKeywordsAttr($query, $value)
    {
        if ($value) {
            $query->where(function ($q) use ($value) {
                $keyword = '%' . escapeLike((string) $value) . '%';
                $q->where('title', 'like', $keyword)
                  ->whereOr('receiver', 'like', $keyword)
                  ->whereOr('template_code', 'like', $keyword);
            });
        }
    }
}
