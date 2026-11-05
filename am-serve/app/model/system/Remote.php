<?php

namespace app\model\system;

use app\basic\BaseModel;

class Remote extends BaseModel
{
    protected $name = 'system_remote';

    protected $json = ['params','header'];

    public function searchKeywordsAttr($model, $value)
    {
        if($value){
            $model->where('name|code', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }
}
