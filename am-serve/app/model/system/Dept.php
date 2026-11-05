<?php

namespace app\model\system;

use app\basic\BaseModel;

class Dept extends BaseModel
{
    protected $name = 'system_dept';
    protected $json = ['childs'];
    public static $treeField = "id, id as value, name as label,name,pid, pid as parent_id";

    /**
     * 关联用户
     */
    public function users()
    {
        return $this->hasMany(User::class, 'dept_id');
    }

    /**
     * 关联父部门
     */
    public function parent()
    {
        return $this->belongsTo(Dept::class, 'pid');
    }

    /**
     * 关联子部门
     */
    public function children()
    {
        return $this->hasMany(Dept::class, 'pid');
    }

    // 递归查询某个部门下的所有子部门
    public static function getAllChild(int $id)
    {
        $data=self::where(['status'=>1])->select()->toArray();
        return findAllChildren($id,$data,true);
   }

    //获取当前人员所有部门的父级部门
    public static function getUserDeptParentIds(int $id)
    {
        $deptIds = DeptUser::where('user_id', $id)->column('dept_id');
        if (empty($deptIds)) {
            return [];
        }

        $deptList = self::where(['status' => 1])->field('id,pid')->select()->toArray();
        $deptMap = array_column($deptList, null, 'id');
        $parentIds = [];

        foreach ($deptIds as $deptId) {
            $pid = $deptMap[$deptId]['pid'] ?? 0;
            while ($pid && isset($deptMap[$pid])) {
                if (in_array($pid, $parentIds)) {
                    break;
                }
                $parentIds[] = $pid;
                $pid = $deptMap[$pid]['pid'];
            }
        }

        return $parentIds;
    }
}
