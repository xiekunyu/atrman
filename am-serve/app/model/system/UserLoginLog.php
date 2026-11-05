<?php

namespace app\model\system;

use app\basic\BaseModel;

class UserLoginLog extends BaseModel
{
    protected $name = 'system_user_login_log';

    /**
     * 临时关闭软删除
     * @return false
     */
    public function getDeleteTimeField(bool $read = false): bool | string
    {
        return false;
    }

    public function searchKeywordsAttr($model, $value): void
    {
        $model->whereLike('username|ip|location|message', '%' . $value . '%');
    }

    public function searchUsernameAttr($model, $value): void
    {
        $model->whereLike('username', '%' . $value . '%');
    }

    public function searchIpAttr($model, $value): void
    {
        $model->whereLike('ip', '%' . $value . '%');
    }

    public function searchStatusAttr($model, $value): void
    {
        $model->where('status', $value);
    }

    public function searchCreateTimeAttr($model, $value): void
    {
        if (is_array($value) && count($value) === 2) {
            $model->whereBetween('create_time', [strtotime($value[0]), strtotime($value[1])]);
        }
    }
}
