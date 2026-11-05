<?php

namespace app\validate\system;

use app\basic\BaseValidate;
use app\model\system\Role;

/**
 * 角色信息验证器
 */
class RoleValidate extends BaseValidate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        'name' => 'require|max:20|unique:' . Role::class,
        'code' => 'require|max:20|unique:' . Role::class,
        'status' => 'in:0,1',
        'sort' => 'number',
        'description' => 'max:200'
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'name.require' => '角色名称必须填写',
        'name.max' => '角色名称最多不能超过20个字符',
        'name.unique' => '角色名称不能重复',
        'code.require' => '角色编码必须填写',
        'code.max' => '角色编码最多不能超过20个字符',
        'code.unique' => '角色编码不能重复',
        'status.in' => '状态必须是0或1',
        'sort.number' => '排序必须是数字',
        'description.max' => '描述最多不能超过200个字符'
    ];

    /**
     * 定义场景
     */
    protected $scene = [
        'save' => [
            'name',
            'code',
            'status',
            'sort',
            'description'
        ],
        'update' => [
            'name',
            'code',
            'status',
            'sort',
            'description'
        ],
    ];
}
