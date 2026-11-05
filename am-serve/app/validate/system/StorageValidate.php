<?php

namespace app\validate\system;

use app\basic\BaseValidate;

class StorageValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|max:50',
        'type' => 'require|max:50',
        'config' => 'max:2000',
        'is_sys' => 'in:0,1',
        'status' => 'require|in:0,1',
    ];

    protected $message = [
        'name.require' => '名称必须填写',
        'name.max' => '名称最多不能超过50个字符',
        'type.require' => '类型必须填写',
        'type.max' => '类型最多不能超过50个字符',
        'config.max' => '配置最多不能超过2000个字符',
        'is_sys.in' => '是否内置必须是0或1',
        'status.require' => '状态必须填写',
        'status.in' => '状态必须是0或1',
    ];

    protected $scene = [
        'save' => [
            'name',
            'type',
            'config',
            'is_sys',
            'status',
        ],
        'update' => [
            'name',
            'type',
            'config',
            'is_sys',
            'status',
        ],
    ];
}
