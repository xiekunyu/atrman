<?php

namespace app\validate\system;

use app\basic\BaseValidate;

/**
 * 菜单信息验证器
 */
class MenuValidate extends BaseValidate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        'type' => 'require|in:1,2,3,4',
        'name' => 'require|max:20',
        'path' => 'max:100',
        'component' => 'max:100',
        'icon' => 'max:50',
        'pid' => 'number',
        'status' => 'in:0,1',
        'sort' => 'number'
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'type.require' => '菜单类型必须填写',
        'type.in' => '菜单类型必须是1、2、3、4中的一个',
        'name.require' => '菜单名称必须填写',
        'name.max' => '菜单名称最多不能超过20个字符',
        'path.max' => '路径最多不能超过100个字符',
        'component.max' => '组件最多不能超过100个字符',
        'icon.max' => '图标最多不能超过50个字符',
        'parent_id.number' => '父菜单ID必须是数字',
        'status.in' => '状态必须是0或1',
        'sort.number' => '排序必须是数字'
    ];

    /**
     * 定义场景
     */
    protected $scene = [
        'save' => [
            'type',
            'name',
            'path',
            'component',
            'icon',
            'pid',
            'status',
            'sort'
        ],
        'update' => [
            'name',
            'path',
            'component',
            'icon',
            'pid',
            'status',
            'sort'
        ],
    ];
}
