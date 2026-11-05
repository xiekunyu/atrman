<?php

namespace app\model\system;

use app\basic\BaseModel;
use app\model\system\File;

class Backup extends BaseModel
{
    public const TYPE_PLUGIN = 1;
    public const TYPE_SYSTEM = 2;

    protected $name = 'system_backup';

    protected $json = ['database_summary', 'plugin_snapshot', 'manifest'];

    public function file()
    {
        return $this->hasOne(File::class, 'id', 'file_id');
    }

    /**
     * 关键词搜索（名称/版本模糊匹配）
     */
    public function searchKeywordsAttr($model, $value)
    {
        if ($value) {
            $model->where('title|version', 'like', '%' . escapeLike((string) $value) . '%');
        }
    }

    /**
     * 备份类型搜索
     */
    public function searchBackupTypeAttr($model, $value)
    {
        if ($value !== '' && $value !== null) {
            $model->where('backup_type', $value);
        }
    }

    /**
     * 状态搜索
     */
    public function searchStatusAttr($model, $value)
    {
        if ($value !== '' && $value !== null) {
            $model->where('status', $value);
        }
    }
}
