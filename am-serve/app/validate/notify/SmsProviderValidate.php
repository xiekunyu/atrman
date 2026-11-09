<?php

namespace app\validate\notify;

use app\basic\BaseValidate;

class SmsProviderValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|max:50',
        'type' => 'require|integer',
        'config' => 'require|array',
    ];

    protected $message = [
        'name.require' => '供应商名称必须填写',
        'name.max' => '供应商名称最多不能超过50个字符',
        'type.require' => '请选择服务商类型',
        'type.integer' => '服务商类型必须为整数',
        'config.require' => '供应商配置必须填写',
        'config.array' => '供应商配置必须是数组格式',
    ];

    protected $scene = [
        'save' => ['name', 'type', 'config'],
        'update' => ['name', 'type', 'config'],
    ];
}
