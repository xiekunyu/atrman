<?php

namespace app\validate\system;

use app\basic\BaseValidate;

/**
 * 部门信息验证器
 */
class DeptValidate extends BaseValidate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        'name' => 'require|max:20',
        'pid' => 'number',
        'status' => 'in:0,1',
        'sort' => 'number',
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'name.require' => '部门名称必须填写',
        'name.max' => '部门名称最多不能超过20个字符',
        'name.unique' => '部门名称不能重复',
        'pid.number' => '父部门ID必须是数字',
        'status.in' => '状态必须是0或1',
        'sort.number' => '排序必须是数字',
    ];

    /**
     * 定义场景
     */
    protected $scene = [
        'save' => [
            'name',
            'status',
            'sort'
        ],
        'update' => [
            'name',
            'pid',
            'status',
            'sort'
        ],
    ];
}
