<?php

namespace app\validate\plugin;

use app\basic\BaseValidate;

class WidgetValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|number',
        'name' => 'require|max:32|regex:^[A-Za-z][A-Za-z0-9_]{0,31}$',
        'title' => 'require|max:32',
        'description' => 'max:64',
        'cate' => 'in:1,2',
        'type' => 'in:widget,page',
        'size' => 'require',
        'status' => 'in:0,1',
    ];

    protected $message = [
        'id.require' => '参数错误',
        'id.number' => '参数错误',
        'name.require' => '小组件标识必须填写',
        'name.max' => '小组件标识最多不能超过32个字符',
        'name.regex' => '小组件标识必须以字母开头，且只能包含字母、数字和下划线',
        'title.require' => '小组件名称必须填写',
        'title.max' => '小组件名称最多不能超过32个字符',
        'description.max' => '小组件描述最多不能超过64字符',
        'cate.in' => '组件分类不正确',
        'type.in' => '组件类型不正确',
        'size.require' => '小组件尺寸必须选择',
        'status.in' => '状态值不正确',
    ];

    protected $scene = [
        'save' => [
            'name',
            'title',
            'description',
            'cate',
            'type',
            'size',
            'status',
        ],
        'update' => [
            'id',
            'title',
            'description',
            'type',
            'size',
            'status',
        ],
    ];
}