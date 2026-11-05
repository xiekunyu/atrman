<?php

namespace app\validate\system;

use app\basic\BaseValidate;
use app\model\system\Remote;

/**
 * 角色信息验证器
 */
class RemoteValidate extends BaseValidate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        'name' => 'require|max:20',
        'code' => 'require|max:20|unique:' . Remote::class,
        'label_field' => 'require|max:64',
        'value_field' => 'require|max:64',
        'type' => 'require|in:1,2',
        'method' => 'require|in:1,2,3,4',
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'name.require' => '名称必须填写',
        'name.max' => '名称最多不能超过20个字符',
        'code.require' => '标识必须填写',
        'code.max' => '标识最多不能超过20个字符',
        'code.unique' => '标识不能重复',
        'label_field.require' => '标签字段必须填写',
        'label_field.max' => '标签字段最多不能超过64个字符',
        'value_field.require' => '值字段必须填写',
        'value_field.max' => '值字段最多不能超过64个字符',
        'type.require' => '类型必须填写',
        'type.in' => '类型必须是1或2',
        'method.require' => '请求方法必须填写',
        'method.in' => '请求方法必须是1或2或3或4',
    ];

    /**
     * 定义场景
     */
    protected $scene = [
        'save' => [
            'name',
            'code',
            'label_field',
            'value_field',
            'type',
            'method',
        ],
        'update' => [
            'name',
            'code',
            'label_field',
            'value_field',
            'type',
            'method',
        ],
    ];
}
