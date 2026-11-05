<?php

namespace app\validate\system;

use app\basic\BaseValidate;
use app\model\system\Dict;

/**
 * 部门信息验证器
 */
class DictValidate extends BaseValidate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        'code' => 'require|max:20|unique:' . Dict::class,
        'name' => 'require|max:20',
        'value' => 'require|max:20',
        'sort' => 'number',
        'color' => 'require|max:20',
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'code.require' => '字典编码必须填写',
        'code.max' => '字典编码最多不能超过20个字符',
        'code.unique' => '字典编码不能重复',
        'name.require' => '字典名称必须填写',
        'name.max' => '字典名称最多不能超过20个字符',
        'value.require' => '字典值必须填写',
        'value.max' => '字典值最多不能超过20个字符',
    ];

    /**
     * 定义场景
     */
    protected $scene = [
        'save' => [
            'name',
            'code'
        ],
        'saveItem' => [
            'name',
            'value'
        ],
    ];
}
