<?php

namespace app\model\system;

use app\basic\BaseModel;

/**
 * 前端错误上报模型（只存不回显：无详情列表接口，仅经 stats 输出聚合计数）。
 * 追加型日志表，无 update_time/delete_time，关闭软删除。
 */
class ErrorReport extends BaseModel
{
    protected $name = 'system_error_report';

    /**
     * 临时关闭软删除
     */
    public function getDeleteTimeField(bool $read = false): bool | string
    {
        return false;
    }
}
