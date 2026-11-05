<?php

namespace app\validate\plugin;

use app\basic\BaseValidate;
use app\model\plugin\App;

class AppValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|max:80|regex:^am_[a-z][a-z0-9_]*$|unique:' . App::class,
        'title' => 'require|max:100',
        'version' => 'require|max:50',
        'author' => 'max:100',
        'path' => 'max:255',
        'icon' => 'max:255',
        'entry' => 'max:255',
        'manage_entry' => 'max:255',
        'type' => 'in:1,2',
        'is_custom' => 'in:0,1',
        'is_core' => 'in:0,1',
        'source' => 'in:1,2',
        'scope' => 'in:1,2,3',
        'platform' => 'in:1,2,3',
        'is_dev' => 'in:0,1',
        'open_mode' => 'in:micro,iframe',
        'status' => 'in:-1,0,1,2',
        'description' => 'max:500',
        'id' => 'number',
        'file_id' => 'number',
        'overwrite' => 'in:0,1,true,false',
        'confirm_name' => 'regex:^am_[a-z][a-z0-9_]*$',
        'plugin_id' => 'number',
        'app_name' => 'regex:^am_[a-z][a-z0-9_]*$',
        'original_name' => 'require|regex:^am_[a-z][a-z0-9_]*$',
        'dependency_name' => 'require|regex:^am_[a-z][a-z0-9_]*$',
        'dependency_title' => 'max:100',
        'dependency_version' => 'max:50|dependencyVersion',
    ];

    protected $message = [
        'name.require' => '插件标识必须填写',
        'name.max' => '插件标识最多不能超过80个字符',
        'name.regex' => '插件标识必须以 am_ 开头，并且只能包含小写字母、数字和下划线',
        'name.unique' => '插件标识不能重复',
        'title.require' => '插件名称必须填写',
        'title.max' => '插件名称最多不能超过100个字符',
        'version.require' => '插件版本必须填写',
        'version.max' => '插件版本最多不能超过50个字符',
        'author.max' => '作者最多不能超过100个字符',
        'path.max' => '插件路径最多不能超过255个字符',
        'icon.max' => '应用图标最多不能超过255个字符',
        'entry.max' => '应用入口最多不能超过255个字符',
        'manage_entry.max' => '管理入口最多不能超过255个字符',
        'type.in' => '类型不正确',
        'is_custom.in' => '应用类型不正确',
        'is_core.in' => '核心应用标记不正确',
        'source.in' => '应用来源不正确',
        'scope.in' => '适用范围不正确',
        'platform.in' => '使用设备不正确',
        'is_dev.in' => '开发模式不正确',
        'open_mode.in' => '打开方式不正确',
        'status.in' => '插件状态不正确',
        'description.max' => '插件描述最多不能超过500字符',
        'id.number' => '参数错误',
        'file_id.number' => '插件安装包参数错误',
        'overwrite.in' => '覆盖安装选项不正确',
        'confirm_name.regex' => '确认插件标识格式不正确',
        'plugin_id.number' => '参数错误',
        'app_name.regex' => '插件标识不正确',
        'original_name.require' => '原依赖标识必须填写',
        'original_name.regex' => '原依赖标识不正确',
        'dependency_name.require' => '依赖插件标识必须填写',
        'dependency_name.regex' => '依赖插件标识必须以 am_ 开头，并且只能包含小写字母、数字和下划线',
        'dependency_title.max' => '依赖名称最多不能超过100个字符',
        'dependency_version.max' => '依赖版本最多不能超过50个字符',
        'dependency_version.dependencyVersion' => '依赖版本格式不正确，请填写如 1.0.0，不需要填写 >=',
    ];

    protected $scene = [
        'save' => [
            'name',
            'title',
            'version',
            'author',
            'icon',
            'entry',
            'manage_entry',
            'type',
            'is_custom',
            'is_core',
            'source',
            'scope',
            'platform',
            'is_dev',
            'open_mode',
            'status',
            'description',
        ],
        'update' => [
            'name',
            'title',
            'version',
            'author',
            'icon',
            'entry',
            'manage_entry',
            'type',
            'is_custom',
            'is_core',
            'source',
            'scope',
            'platform',
            'is_dev',
            'open_mode',
            'status',
            'description',
        ],
        'dependencySave' => [
            'plugin_id',
            'app_name',
            'dependency_name',
            'dependency_title',
            'dependency_type',
            'dependency_version',
        ],
        'dependencyUpdate' => [
            'plugin_id',
            'app_name',
            'original_name',
            'dependency_name',
            'dependency_title',
            'dependency_type',
            'dependency_version',
        ],
        'dependencyDelete' => [
            'plugin_id',
            'app_name',
            'dependency_name',
        ],
        'packageInspect' => [
            'file_id',
            'overwrite',
        ],
        'uninstall' => [
            'id',
            'confirm_name',
        ],
    ];

    /** 校验依赖版本，版本号不要带比较符。 */
    protected function dependencyVersion(mixed $value): bool
    {
        $value = trim((string)$value);
        if ($value === '') {
            return true;
        }
        return (bool)preg_match('/^v?\d+(\.\d+){0,3}(-[0-9A-Za-z.-]+)?$/', $value);
    }
}