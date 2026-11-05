<?php

namespace app\model\system;

use app\basic\BaseModel;

class SystemLog extends BaseModel
{
    protected $name = 'system_log';

    public function searchKeywordsAttr($model, $value): void
    {
        $model->whereLike('username|business_name|router|ip', '%' . $value . '%');
    }

    public function searchUsernameAttr($model, $value): void
    {
        $model->whereLike('username', '%' . $value . '%');
    }

    public function searchBusinessNameAttr($model, $value): void
    {
        $model->whereLike('business_name', '%' . $value . '%');
    }

    public function searchRouterAttr($model, $value): void
    {
        $model->whereLike('router', '%' . $value . '%');
    }

    public function searchIpAttr($model, $value): void
    {
        $model->whereLike('ip', '%' . $value . '%');
    }

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'created_by');
    }
}
