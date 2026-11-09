<?php

namespace app\model\system;

use app\basic\BaseModel;

class File extends BaseModel
{
    protected $name = 'system_file';

    protected $json=['extends'];

    public function searchKeywordsAttr($model, $value)
    {
        if($value){
            $model->where('name|path', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }
       
    /**
     * 关联父文件/目录
     */
    public function parent()
    {
        return $this->belongsTo(File::class, 'pid');
    }

    /**
     * 关联子文件/目录
     */
    public function children()
    {
        return $this->hasMany(File::class, 'pid');
    }

    // 关联储存器
    public function storage(){
        return $this->hasOne(Storage::class,'id','storage_id');
    }

    // 关联用户
    public function user(){
        return $this->hasOne(User::class,'id','created_by');
    }

    /**
     * 递归查询某个目录下的所有子文件/目录
     */
    public static function getAllChild($id)
    {
        $data = self::where(['status' => 1])->select()->toArray();
        return findAllChildren($id, $data, 'id', 'pid', true);
    }

    /**
     * 获取文件面包屑
     * @param array $config
     */
    public static function getTreeNode($arr, $pid, $storage_id, $rootName = '根目录')
    {
        if ($pid == 0) {
            $top = ['id' => 0, 'pid' => 0, 'name' => $rootName, 'storage_id' => $storage_id, 'type' => 1];
            array_unshift($arr, $top);
        } else {
            $res = self::find($pid);
            if ($res) {
                $res = $res->toArray();
                array_unshift($arr, $res);
                if ($res['pid'] >= 0) {
                    return self::getTreeNode($arr, $res['pid'], $storage_id);
                }
            }
        }
        return $arr;
    }
}