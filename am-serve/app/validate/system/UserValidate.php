<?php
// +----------------------------------------------------------------------
// | atrman企业快速开发框架
// +----------------------------------------------------------------------
// | Author: raingad <raingad@foxmail.com>
// +----------------------------------------------------------------------
namespace app\validate\system;

use app\basic\BaseValidate;
use app\model\system\User;

/**
 * 用户信息验证器
 */
class UserValidate extends BaseValidate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        'username' => 'require|max:16|unique:' . User::class,
        'password' => 'require|min:6|max:16',
        'nickname' => 'require|max:20',
        'email' => 'email|max:50',
        'mobile' => 'mobile|max:11',
        'status' => 'in:0,1',
        'role_id' => 'number',
        'dept_id' => 'number'
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'username.require' => '用户名必须填写',
        'username.max' => '用户名最多不能超过16个字符',
        'username.unique' => '用户名不能重复',
        'password.require' => '密码必须填写',
        'password.min' => '密码最少为6位',
        'password.max' => '密码长度不能超过16位',
        'nickname.require' => '昵称必须填写',
        'nickname.max' => '昵称最多不能超过20个字符',
        'email.email' => '邮箱格式错误',
        'email.max' => '邮箱最多不能超过50个字符',
        'mobile.mobile' => '手机号格式错误',
        'mobile.max' => '手机号最多不能超过11个字符',
        'status.in' => '状态必须是0或1',
        'role_id.number' => '角色ID必须是数字',
        'dept_id.number' => '部门ID必须是数字'
    ];

    /**
     * 定义场景
     */
    protected $scene = [
        'save' => [
            'username',
            'password',
            'nickname',
            'email',
            'mobile',
            'status',
            'role_id',
            'dept_id'
        ],
        'update' => [
            'nickname',
            'email',
            'mobile',
            'status',
            'role_id',
            'dept_id'
        ],
        'editPass' => [
            'password' => 'require|min:6|max:16'
        ]
    ];
}
