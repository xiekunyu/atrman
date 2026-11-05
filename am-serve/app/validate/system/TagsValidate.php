<?php

namespace app\validate\system;

use app\basic\BaseValidate;
use app\model\system\Tags;

/**
 * 标签信息验证器
 */
class TagsValidate extends BaseValidate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        'name' => 'require|unique:' . Tags::class,
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'name.require' => '标签名称必须填写',
        'name.unique' => '标签名称不能重复', 
    ];

    protected $scene = [
        'save' => ['name'],
        'update' => ['name'],
    ];
}
