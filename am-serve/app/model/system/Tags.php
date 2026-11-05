<?php

namespace app\model\system;

use app\basic\BaseModel;

class Tags extends BaseModel
{
    protected $name = 'system_tags';
    protected $json      = ["user_ids","dept_ids"];
    protected $jsonAssoc = true;

    public static function getTagUserIds($tags_id)
    {
        $tags = self::findOrEmpty($tags_id);
        $user_ids = $tags->user_ids ?? [];
        
        $dept_ids = $tags->dept_ids;
        if ($dept_ids) {
            $depts = Dept::where(['status' => 1])->select()->toArray();
            $allDeptIds = [];
            foreach ($dept_ids as $dept_id) {
                $deptChildren = findAllChildren($dept_id, $depts,true);
                $allDeptIds = array_merge($allDeptIds, array_column($deptChildren, 'id'));
            }
            $allDeptIds = array_unique($allDeptIds);
            $deptUserIds = DeptUser::whereIn('dept_id', $allDeptIds)->column('user_id');
            $user_ids = array_merge($user_ids, $deptUserIds);
        }
        
        $user_ids = array_unique(array_filter($user_ids));
        return array_values($user_ids);
    }



}
