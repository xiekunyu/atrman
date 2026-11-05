<?php

namespace app\model\system;

use app\basic\BaseModel;

class Storage extends BaseModel
{
    protected $name = 'system_storage';

    protected $json = ['config'];

    public function searchKeywordsAttr(\think\db\BaseQuery $model, string $value)
    {
        if ($value) {
            $model->where('name|type', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    public function configInfo(){
        return  $this->hasOne(Config::class,'id','type');
    }

    public function getStorageConfig(int $id){
        $info = $this->with(['configInfo'])->findOrEmpty($id);
        $code=$info->adapter ?? 'local';
        if(!$info->isEmpty()){
            $info=$info->toArray();
            $config=(array)($info['config'] ?? []);
            $config['disk']=$code;
            return $config;
        }
        $config=config('plugin.tinywan.storage.app.storage.'.$code);
        return $config;
    }
}
