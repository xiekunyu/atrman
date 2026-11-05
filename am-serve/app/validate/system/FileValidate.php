<?php

namespace app\validate\system;

use app\basic\BaseValidate;

/**
 * 文件信息验证器
 */
class FileValidate extends BaseValidate
{
    /**
     * 定义验证规则
     */
    protected $rule = [
        'type' => 'in:0,1',
        'pid' => 'number',
        'level' => 'number',
        'name' => 'require|max:255',
        'url' => 'require|max:128',
        'path' => 'require|max:512',
        'ext' => 'max:20',
        'size' => 'number|min:0',
        'md5' => 'require|length:32',
        'storage_id' => 'number',
        'is_lock' => 'in:0,1',
        'file_type' => 'in:1,2,3,4,99',
        'mime_type' => 'max:128',
        'status' => 'in:0,1',
    ];

    /**
     * 定义错误信息
     */
    protected $message = [
        'type.in' => '类型必须是0或1',
        'pid.number' => '父级ID必须是数字',
        'level.number' => '层级必须是数字',
        'name.require' => '文件名称必须填写',
        'name.max' => '文件名称最多不能超过255个字符',
        'url.require' => '文件URL必须填写',
        'url.max' => '文件URL最多不能超过128个字符',
        'path.require' => '文件存储路径必须填写',
        'path.max' => '文件存储路径最多不能超过512个字符',
        'ext.max' => '文件后缀最多不能超过20个字符',
        'size.number' => '文件大小必须是数字',
        'size.min' => '文件大小不能小于0',
        'md5.require' => '文件MD5值必须填写',
        'md5.length' => '文件MD5值长度必须是32位',
        'storage_id.number' => '所属磁盘ID必须是数字',
        'is_lock.in' => '是否锁定必须是0或1',
        'file_type.in' => '文件类型必须是1-4或99',
        'mime_type.max' => '文件MIME类型最多不能超过128个字符',
        'status.in' => '状态必须是0或1',
    ];

    /**
     * 定义场景
     */
    protected $scene = [
        'save' => [
            'name'
        ],
        'update' => [
            'name',
        ],
    ];
}