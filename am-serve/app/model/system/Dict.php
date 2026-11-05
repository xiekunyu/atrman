<?php

namespace app\model\system;

use app\basic\BaseModel;

class Dict extends BaseModel
{
    protected $name = 'system_dict';
    
    /**
     * 关键词搜索器
     */
    public function searchKeywordsAttr($model, $value)
    {
        if ($value) {
            $model->where('code|name|value', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }
}
