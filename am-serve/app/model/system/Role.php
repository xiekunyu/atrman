<?php

namespace app\model\system;

use app\basic\BaseModel;

class Role extends BaseModel
{
    protected $name = 'system_role';

    public function searchKeywordsAttr($model, $value)
    {
        if($value){
            $model->where('name|code|remark', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    /**
     * 关联用户
     */
    public function users()
    {
        return $this->belongsToMany(User::class, UserRole::class, 'user_id', 'role_id');
    }

    /**
     * 关联菜单
     */
    public function menus()
    {
        return $this->belongsToMany(Menu::class, RoleMenu::class, 'menu_id', 'role_id');
    }
}
