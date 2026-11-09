<?php

namespace app\validate\notify;

use app\basic\BaseValidate;
use app\model\notify\NotifyTemplate;

class NotifyTemplateValidate extends BaseValidate
{
    protected $rule = [
        'name' => 'require|max:100',
        'code' => 'require|max:50',
    ];

    protected $message = [
        'name.require' => '模板名称必须填写',
        'name.max' => '模板名称最多不能超过100个字符',
        'code.require' => '模板编码必须填写',
        'code.max' => '模板编码最多不能超过50个字符',
    ];

    protected $scene = [
        'save' => ['name', 'code'],
        'update' => ['name'],
    ];
}
