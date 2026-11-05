<?php

namespace app\validate\system;

use app\basic\BaseValidate;

class ConfigValidate extends BaseValidate
{
    protected $rule = [
        'pid' => 'number',
        'name' => 'require|max:50',
        'code' => 'require|max:50',
        'value' => 'max:1000',
        'form_type' => 'max:50',
        'option' => 'max:1000',
    ];

    protected $message = [
        'pid.number' => '父ID必须是数字',
        'name.require' => '名称必须填写',
        'name.max' => '名称最多不能超过50个字符',
        'code.require' => '字段名必须填写',
        'code.max' => '字段名最多不能超过50个字符',
        'value.max' => '字段值最多不能超过1000个字符',
        'form_type.max' => '表单类型最多不能超过50个字符',
        'option.max' => '配置项最多不能超过1000个字符',
    ];

    protected $scene = [
        'save' => [
            'pid',
            'name',
            'code',
        ],
        'update' => [
            'pid',
            'name',
            'code',
            'value',
            'form_type',
            'option',
            'is_sys',
            'is_edit',
            'app_name',
            'sort',
        ],
    ];
}
