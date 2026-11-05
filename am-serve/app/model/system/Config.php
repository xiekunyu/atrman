<?php

namespace app\model\system;

use app\basic\BaseModel;
use app\cache\ConfigCache;

class Config extends BaseModel
{
    protected $name = 'system_config';

    protected $json = ['options'];

    public function searchKeywordsAttr($model, $value): void
    {
        if ($value) {
            $model->where('name|code', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    public function searchNameAttr($model, $value): void
    {
        if ($value) {
            $model->where('name', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    public function searchKeyAttr($model, $value): void
    {
        if ($value) {
            $model->where('code', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    public function searchAppNameAttr($model, $value): void
    {
        if ($value !== '' && $value !== null) {
            $model->where('app_name', $value);
        }
    }

    public function children()
    {
        return $this->hasMany(self::class,'pid','id');
    }
    
    public static function getConfig(string $code, string $appName = '')
    {
        return ConfigCache::getConfig($code, $appName);
    }

    public static function getConfigFromDb(string $code, string $appName = ''): array
    {
        // 仅取顶层配置组（pid=0）；归属为插件时按 app_name 精确过滤以区分同名组，基座（空/system）不过滤保持向后兼容
        $query = self::where('code', $code)->where('pid', 0);
        $appName = \app\cache\ConfigCache::normalizeAppName($appName);
        if ($appName !== '') {
            $query->where('app_name', $appName);
        }
        $info = $query->with(['children'])->find();
        $config=[];
        if($info && $info['children']){
            $children=$info['children'];
            foreach($children as $val){
                $item=is_array($val) ? $val : $val->toArray();
                $item=self::parseConfigItem($item);
                $config[$item['code']]=$item['value'];
                if(!empty($item['displayData'])){
                    $config[$item['code'].'_displayData']=$item['displayData'];
                }
            }
        }
        return $config;
    }

    public static function parseConfigItem(array $item): array
    {
        $value=self::parseValue($item['value'] ?? null);
        $item['value']=$value;
        $item['displayData']=self::getDisplayData($item['form_type'] ?? '', $value);
        return $item;
    }

    public static function parseValue(mixed $value): mixed
    {
        if($value !== '' && $value !== null){
            if(is_string($value)){
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    return $decoded;
                }
            }
            if(is_numeric($value)){
                return (float)$value;
            }
            return $value;
        }
        return null;
    }

    public static function getDisplayData(string $formType, mixed $value): array
    {
        if(empty($value)){
            return [];
        }
        if(in_array($formType,['image','file'])){
            return File::where('id','in',normalizeIds($value, false))->select()->toArray();
        }elseif(in_array($formType,['user','member'])){
            return User::where('id','in',normalizeIds($value, false))->select()->toArray();
        }elseif(in_array($formType,['dept','department'])){
            return Dept::where('id','in',normalizeIds($value, false))->select()->toArray();
        }elseif($formType=='structure'){
            return User::getStructure($value);
        }
        return [];
    }
}
